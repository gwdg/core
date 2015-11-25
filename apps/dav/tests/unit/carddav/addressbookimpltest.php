<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
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


use OCA\DAV\CardDAV\AddressBookImpl;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property\Text;
use Test\TestCase;

class AddressBookImplTest extends TestCase {

	/** @var AddressBookImpl  */
	private $addressBookImpl;

	/** @var  array */
	private $addressBookInfo;

	/** @var  \OCA\DAV\CardDAV\AddressBook | \PHPUnit_Framework_MockObject_MockObject */
	private $addressBook;

	/** @var  \OCA\DAV\CardDAV\CardDavBackend | \PHPUnit_Framework_MockObject_MockObject */
	private $backend;

	/** @var  \OCA\DAV\CardDAV\Database | \PHPUnit_Framework_MockObject_MockObject */
	private $database;

	public function setUp() {
		parent::setUp();

		$this->addressBookInfo = [
			'id' => 42,
			'{DAV:}displayname' => 'display name'
		];
		$this->addressBook = $this->getMockBuilder('OCA\DAV\CardDAV\AddressBook')
			->disableOriginalConstructor()->getMock();
		$this->backend = $this->getMockBuilder('\OCA\DAV\CardDAV\CardDavBackend')
			->disableOriginalConstructor()->getMock();
		$this->database = $this->getMockBuilder('OCA\DAV\CardDAV\Database')
			->disableOriginalConstructor()->getMock();

		$this->addressBookImpl = new AddressBookImpl(
			$this->addressBook,
			$this->addressBookInfo,
			$this->backend,
			$this->database
		);
	}

	public function testGetKey() {
		$this->assertSame($this->addressBookInfo['id'],
			$this->addressBookImpl->getKey());
	}

	public function testGetDisplayName() {
		$this->assertSame($this->addressBookInfo['{DAV:}displayname'],
			$this->addressBookImpl->getDisplayName());
	}

	public function testSearch() {

		/** @var \PHPUnit_Framework_MockObject_MockObject | AddressBookImpl $addressBookImpl */
		$addressBookImpl = $this->getMockBuilder('OCA\DAV\CardDAV\AddressBookImpl')
			->setConstructorArgs(
				[
					$this->addressBook,
					$this->addressBookInfo,
					$this->backend,
					$this->database
				]
			)
			->setMethods(['vCard2Array'])
			->getMock();

		$pattern = 'pattern';
		$searchProperties = 'properties';
		$vCard1 = new VCard();
		$vCard1->add(new Text($vCard1, 'UID', 'vcard1-uid'));
		$vCard2 = new VCard();
		$vCard2->add(new Text($vCard2, 'UID', 'vcard2-uid'));

		$this->database->expects($this->once())->method('searchContact')
			->with($this->addressBookInfo['id'], $pattern, $searchProperties)
			->willReturn(
				[
					$vCard1->serialize(),
					$vCard2->serialize()
				]
			);

		$addressBookImpl->expects($this->exactly(2))->method('vCard2Array')
			->willReturn('vCard');

		$result = $addressBookImpl->search($pattern, $searchProperties, []);
		$this->assertTrue((is_array($result)));
		$this->assertSame(2, count($result));
	}

	public function testDelete() {
		$cardId = 1;
		$cardUri = 'cardUri';
		$this->database->expects($this->once())->method('getCardUri')
			->with($cardId)->willReturn($cardUri);
		$this->backend->expects($this->once())->method('deleteCard')
			->with($this->addressBookInfo['id'], $cardUri)
			->willReturn(true);

		$this->assertTrue($this->addressBookImpl->delete($cardId));
	}
}
