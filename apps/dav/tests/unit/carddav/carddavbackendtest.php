<?php
/**
 * @author Lukas Reschke <lukas@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Tests\Unit\CardDAV;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\Connector\Sabre\Principal;
use OCP\IDBConnection;
use OCP\ILogger;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property\Text;
use Test\TestCase;

/**
 * Class CardDavBackendTest
 *
 * @group DB
 *
 * @package OCA\DAV\Tests\Unit\CardDAV
 */
class CardDavBackendTest extends TestCase {

	/** @var CardDavBackend */
	private $backend;

	/** @var  Principal | \PHPUnit_Framework_MockObject_MockObject */
	private $principal;

	/** @var  ILogger | \PHPUnit_Framework_MockObject_MockObject */
	private $logger;

	/** @var  IDBConnection */
	private $db;

	const UNIT_TEST_USER = 'carddav-unit-test';

	public function setUp() {
		parent::setUp();

		$this->principal = $this->getMockBuilder('OCA\DAV\Connector\Sabre\Principal')
			->disableOriginalConstructor()
			->setMethods(['getPrincipalByPath'])
			->getMock();
		$this->principal->method('getPrincipalByPath')
			->willReturn([
				'uri' => 'principals/best-friend'
			]);
		$this->logger = $this->getMock('\OCP\ILogger');

		$this->db = \OC::$server->getDatabaseConnection();

		$this->backend = new CardDavBackend($this->db, $this->principal, $this->logger);

		// start every test with a empty cards_properties and cards table
		$query = $this->db->getQueryBuilder();
		$query->delete('cards_properties')->execute();
		$query = $this->db->getQueryBuilder();
		$query->delete('cards')->execute();


		$this->tearDown();
	}

	public function tearDown() {
		parent::tearDown();

		if (is_null($this->backend)) {
			return;
		}
		$books = $this->backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		foreach ($books as $book) {
			$this->backend->deleteAddressBook($book['id']);
		}
	}

	public function testAddressBookOperations() {

		// create a new address book
		$this->backend->createAddressBook(self::UNIT_TEST_USER, 'Example', []);

		$books = $this->backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($books));
		$this->assertEquals('Example', $books[0]['{DAV:}displayname']);

		// update it's display name
		$patch = new PropPatch([
			'{DAV:}displayname' => 'Unit test',
			'{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Addressbook used for unit testing'
		]);
		$this->backend->updateAddressBook($books[0]['id'], $patch);
		$patch->commit();
		$books = $this->backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($books));
		$this->assertEquals('Unit test', $books[0]['{DAV:}displayname']);
		$this->assertEquals('Addressbook used for unit testing', $books[0]['{urn:ietf:params:xml:ns:carddav}addressbook-description']);

		// delete the address book
		$this->backend->deleteAddressBook($books[0]['id']);
		$books = $this->backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		$this->assertEquals(0, count($books));
	}

	public function testCardOperations() {

		$backend = $this->getMockBuilder('OCA\DAV\CardDAV\CardDavBackend')
				->setConstructorArgs([$this->db, $this->principal, $this->logger])
				->setMethods(['updateProperties', 'purgeProperties'])->getMock();

		// create a new address book
		$backend->createAddressBook(self::UNIT_TEST_USER, 'Example', []);
		$books = $backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($books));
		$bookId = $books[0]['id'];

		$uri = $this->getUniqueID('card');
		// updateProperties is expected twice, once for createCard and once for updateCard
		$backend->expects($this->at(0))->method('updateProperties')->with($bookId, $uri, '');
		$backend->expects($this->at(1))->method('updateProperties')->with($bookId, $uri, '***');
		// create a card
		$backend->createCard($bookId, $uri, '');

		// get all the cards
		$cards = $backend->getCards($bookId);
		$this->assertEquals(1, count($cards));
		$this->assertEquals('', $cards[0]['carddata']);

		// get the cards
		$card = $backend->getCard($bookId, $uri);
		$this->assertNotNull($card);
		$this->assertArrayHasKey('id', $card);
		$this->assertArrayHasKey('uri', $card);
		$this->assertArrayHasKey('lastmodified', $card);
		$this->assertArrayHasKey('etag', $card);
		$this->assertArrayHasKey('size', $card);
		$this->assertEquals('', $card['carddata']);

		// update the card
		$backend->updateCard($bookId, $uri, '***');
		$card = $backend->getCard($bookId, $uri);
		$this->assertEquals('***', $card['carddata']);

		// delete the card
		$backend->expects($this->once())->method('purgeProperties')->with($bookId, $card['id']);
		$backend->deleteCard($bookId, $uri);
		$cards = $backend->getCards($bookId);
		$this->assertEquals(0, count($cards));
	}

	public function testMultiCard() {
		// create a new address book
		$this->backend->createAddressBook(self::UNIT_TEST_USER, 'Example', []);
		$books = $this->backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($books));
		$bookId = $books[0]['id'];

		// create a card
		$uri0 = $this->getUniqueID('card');
		$this->backend->createCard($bookId, $uri0, '');
		$uri1 = $this->getUniqueID('card');
		$this->backend->createCard($bookId, $uri1, '');
		$uri2 = $this->getUniqueID('card');
		$this->backend->createCard($bookId, $uri2, '');

		// get all the cards
		$cards = $this->backend->getCards($bookId);
		$this->assertEquals(3, count($cards));
		$this->assertEquals('', $cards[0]['carddata']);
		$this->assertEquals('', $cards[1]['carddata']);
		$this->assertEquals('', $cards[2]['carddata']);

		// get the cards
		$cards = $this->backend->getMultipleCards($bookId, [$uri1, $uri2]);
		$this->assertEquals(2, count($cards));
		foreach($cards as $card) {
			$this->assertArrayHasKey('id', $card);
			$this->assertArrayHasKey('uri', $card);
			$this->assertArrayHasKey('lastmodified', $card);
			$this->assertArrayHasKey('etag', $card);
			$this->assertArrayHasKey('size', $card);
			$this->assertEquals('', $card['carddata']);
		}

		// delete the card
		$this->backend->deleteCard($bookId, $uri0);
		$this->backend->deleteCard($bookId, $uri1);
		$this->backend->deleteCard($bookId, $uri2);
		$cards = $this->backend->getCards($bookId);
		$this->assertEquals(0, count($cards));
	}

	public function testSyncSupport() {
		// create a new address book
		$this->backend->createAddressBook(self::UNIT_TEST_USER, 'Example', []);
		$books = $this->backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($books));
		$bookId = $books[0]['id'];

		// fist call without synctoken
		$changes = $this->backend->getChangesForAddressBook($bookId, '', 1);
		$syncToken = $changes['syncToken'];

		// add a change
		$uri0 = $this->getUniqueID('card');
		$this->backend->createCard($bookId, $uri0, '');

		// look for changes
		$changes = $this->backend->getChangesForAddressBook($bookId, $syncToken, 1);
		$this->assertEquals($uri0, $changes['added'][0]);
	}

	public function testSharing() {
		$this->backend->createAddressBook(self::UNIT_TEST_USER, 'Example', []);
		$books = $this->backend->getAddressBooksForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($books));

		$this->backend->updateShares('Example', [['href' => 'principal:principals/best-friend']], []);

		$shares = $this->backend->getShares('Example');
		$this->assertEquals(1, count($shares));

		// adding the same sharee again has no effect
		$this->backend->updateShares('Example', [['href' => 'principal:principals/best-friend']], []);

		$shares = $this->backend->getShares('Example');
		$this->assertEquals(1, count($shares));

		$books = $this->backend->getAddressBooksForUser('principals/best-friend');
		$this->assertEquals(1, count($books));

		$this->backend->updateShares('Example', [], [['href' => 'principal:principals/best-friend']]);

		$shares = $this->backend->getShares('Example');
		$this->assertEquals(0, count($shares));

		$books = $this->backend->getAddressBooksForUser('principals/best-friend');
		$this->assertEquals(0, count($books));
	}

	public function testUpdateProperties() {

		$bookId = 42;
		$cardUri = 'card-uri';

		$backend = $this->getMockBuilder('OCA\DAV\CardDAV\CardDavBackend')
			->setConstructorArgs([$this->db, $this->principal, $this->logger])
			->setMethods(['getCardId'])->getMock();

		$backend->expects($this->any())->method('getCardId')->willReturn($cardUri);

		// add properties for new vCard
		$vCard = new VCard();
		$vCard->add(new Text($vCard, 'UID', $cardUri));
		$vCard->add(new Text($vCard, 'FN', 'John Doe'));
		$this->invokePrivate($backend, 'updateProperties', [$bookId, $cardUri, $vCard->serialize()]);

		$query = $this->db->getQueryBuilder();
		$result = $query->select('*')->from('cards_properties')->execute()->fetchAll();

		$this->assertSame(2, count($result));

		$this->assertSame('UID', $result[0]['name']);
		$this->assertSame($cardUri, $result[0]['value']);
		$this->assertSame($bookId, (int)$result[0]['addressbookid']);
		$this->assertSame($cardUri, $result[0]['cardid']);

		$this->assertSame('FN', $result[1]['name']);
		$this->assertSame('John Doe', $result[1]['value']);
		$this->assertSame($bookId, (int)$result[1]['addressbookid']);
		$this->assertSame($cardUri, $result[1]['cardid']);

		// update properties for existing vCard
		$vCard = new VCard();
		$vCard->add(new Text($vCard, 'FN', 'John Doe'));
		$this->invokePrivate($backend, 'updateProperties', [$bookId, $cardUri, $vCard->serialize()]);

		$query = $this->db->getQueryBuilder();
		$result = $query->select('*')->from('cards_properties')->execute()->fetchAll();

		$this->assertSame(1, count($result));

		$this->assertSame('FN', $result[0]['name']);
		$this->assertSame('John Doe', $result[0]['value']);
		$this->assertSame($bookId, (int)$result[0]['addressbookid']);
		$this->assertSame($cardUri, $result[0]['cardid']);
	}

	public function testPurgeProperties() {

		$query = $this->db->getQueryBuilder();
		$query->insert('cards_properties')
			->values(
				[
					'addressbookid' => $query->createNamedParameter(1),
					'cardid' => $query->createNamedParameter(1),
					'name' => $query->createNamedParameter('name1'),
					'value' => $query->createNamedParameter('value1'),
					'preferred' => $query->createNamedParameter('preferred1')
				]
			);
		$query->execute();

		$query = $this->db->getQueryBuilder();
		$query->insert('cards_properties')
			->values(
				[
					'addressbookid' => $query->createNamedParameter(1),
					'cardid' => $query->createNamedParameter(2),
					'name' => $query->createNamedParameter('name2'),
					'value' => $query->createNamedParameter('value2'),
					'preferred' => $query->createNamedParameter('preferred2')
				]
			);
		$query->execute();

		$this->invokePrivate($this->backend, 'purgeProperties', [1, 1]);

		$query = $this->db->getQueryBuilder();
		$result = $query->select('*')->from('cards_properties')->execute()->fetchAll();
		$this->assertSame(1, count($result));
		$this->assertSame(1 ,(int)$result[0]['addressbookid']);
		$this->assertSame(2 ,(int)$result[0]['cardid']);

	}

	public function testGetCardId() {
		$query = $this->db->getQueryBuilder();

		$query->insert('cards')
			->values(
				[
					'addressbookid' => $query->createNamedParameter(1),
					'carddata' => $query->createNamedParameter(''),
					'uri' => $query->createNamedParameter('uri'),
					'lastmodified' => $query->createNamedParameter(4738743),
					'etag' => $query->createNamedParameter('etag'),
					'size' => $query->createNamedParameter(120)
				]
			);
		$query->execute();
		$id = $query->getLastInsertId();

		$this->assertSame($id,
			$this->invokePrivate($this->backend, 'getCardId', ['uri']));
	}

	/**
	 * @expectedException InvalidArgumentException
	 */
	public function testGetCardIdFailed() {
		$this->invokePrivate($this->backend, 'getCardId', ['uri']);
	}

}
