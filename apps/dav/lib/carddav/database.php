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


namespace OCA\DAV\CardDAV;


use OCP\IDBConnection;
use Sabre\VObject\Component\VCard;

/**
 * Class Database
 *
 * handle all db calls for the CardDav back-end
 *
 * @group DB
 * @package OCA\DAV\CardDAV
 */
class Database {

	/** @var IDBConnection */
	private $connection;

	/** @var string */
	private $dbCardsTable = 'cards';

	/** @var string */
	private $dbCardsPropertiesTable = 'cards_properties';

	/**
	 * Database constructor.
	 *
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * get URI from a given contact
	 *
	 * @param int $id
	 * @return string
	 */
	public function getCardUri($id) {
		$query = $this->connection->getQueryBuilder();
		$query->select('uri')->from($this->dbCardsTable)
			->where($query->expr()->eq('id', $query->createParameter('id')))
			->setParameter('id', $id);

		$result = $query->execute();
		$uri = $result->fetch();
		$result->closeCursor();

		return $uri['uri'];
	}

	/**
	 * return contact with the given URI
	 *
	 * @param string $uri
	 * @returns array
	 */
	public function getContact($uri) {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')->from($this->dbCardsTable)
				->where($query->expr()->eq('uri', $query->createParameter('uri')))
				->setParameter('uri', $uri);
		$result = $query->execute();
		$contacts = $result->fetchAll();
		$result->closeCursor();

		return $contacts;
	}

	/**
	 * search contact
	 *
	 * @param int $addressBookId
	 * @param string $pattern which should match within the $searchProperties
	 * @param array $searchProperties defines the properties within the query pattern should match
	 * @return array an array of contacts which are arrays of key-value-pairs
	 */
	public function searchContact($addressBookId, $pattern, $searchProperties) {
		$query = $this->connection->getQueryBuilder();
		$query->selectDistinct('carddate', 'c')
			->from($this->dbCardsTable, 'c')
			->leftJoin('c', $this->dbCardsPropertiesTable, 'cp', $query->expr()->eq('cp.cardid', 'c.id'));
		foreach ($searchProperties as $property) {
			$query->orWhere(
					$query->expr()->andX(
						$query->expr()->eq('cp.name', $query->createNamedParameter($property)),
						$query->expr()->like('cp.value', $query->createNamedParameter('%' . $this->connection->escapeLikeParameter($pattern) . '%'))
					)
			);
		}
		$query->andWhere($query->expr()->eq('cp.addressbookid', $query->createNamedParameter($addressBookId)));
		$result =  $query->execute();
		$cards = $result->fetchAll();
		$result->closeCursor();

		return array_map(function($array) {return $array['carddata'];}, $cards);

	}


}
