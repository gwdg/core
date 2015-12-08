<?php

namespace OCA\DAV;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CardDAV\AddressBookRoot;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\Connector\Sabre\Principal;
use OCA\DAV\DAV\SystemPrincipalBackend;
use Sabre\CalDAV\CalendarRoot;
use Sabre\CalDAV\Principal\Collection;
use Sabre\DAV\SimpleCollection;

class RootCollection extends SimpleCollection {

	public function __construct() {
		$config = \OC::$server->getConfig();
		$db = \OC::$server->getDatabaseConnection();
		$principalBackend = new Principal(
				$config,
				\OC::$server->getUserManager()
		);
		// as soon as debug mode is enabled we allow listing of principals
		$disableListing = !$config->getSystemValue('debug', false);

		// setup the first level of the dav tree
		$userPrincipals = new Collection($principalBackend, 'principals/users');
		$userPrincipals->disableListing = $disableListing;
		$systemPrincipals = new Collection(new SystemPrincipalBackend(), 'principals/system');
		$systemPrincipals->disableListing = $disableListing;
		$filesCollection = new Files\RootCollection($principalBackend, 'principals/users');
		$filesCollection->disableListing = $disableListing;
		$caldavBackend = new CalDavBackend($db);
		$calendarRoot = new CalendarRoot($principalBackend, $caldavBackend, 'principals/users');
		$calendarRoot->disableListing = $disableListing;
		$systemTagCollection = new SystemTag\SystemTagsByIdCollection(
			\OC::$server->getSystemTagManager()
		);
		$systemTagRelationsCollection = new SystemTag\SystemTagsRelationsCollection(
			\OC::$server->getSystemTagManager(),
			\OC::$server->getSystemTagObjectMapper()
		);

		$usersCardDavBackend = new CardDavBackend($db, $principalBackend, \OC::$server->getLogger());
		$usersAddressBookRoot = new AddressBookRoot($principalBackend, $usersCardDavBackend, 'principals/users');
		$usersAddressBookRoot->disableListing = $disableListing;

		$systemCardDavBackend = new CardDavBackend($db, $principalBackend, \OC::$server->getLogger());
		$systemAddressBookRoot = new AddressBookRoot(new SystemPrincipalBackend(), $systemCardDavBackend, 'principals/system');
		$systemAddressBookRoot->disableListing = $disableListing;

		$children = [
				new SimpleCollection('principals', [
						$userPrincipals,
						$systemPrincipals]),
				$filesCollection,
				$calendarRoot,
				new SimpleCollection('addressbooks', [
						$usersAddressBookRoot,
						$systemAddressBookRoot]),
				$systemTagCollection,
				$systemTagRelationsCollection,
		];

		parent::__construct('root', $children);
	}

}
