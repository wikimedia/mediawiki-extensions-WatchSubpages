<?php
/**
 * Implements Special:WatchSubpages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;

/**
 * Provides the UI through which users can perform editing
 * operations on their watchlist
 *
 * @ingroup SpecialPage
 * @author Prod
 * @author Rob Church <robchur@gmail.com>
 */
class WatchSubpages extends SpecialPage {
	protected $successMessage;

	protected $hideRedirects = false;

	private $badItems = [];

	/**
	 * @var TitleParser
	 */
	private $titleParser;

	public function __construct() {
		parent::__construct( 'WatchSubpages', 'watchsubpages' );
	}

	/**
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * Initialize any services we'll need (unless it has already been provided via a setter).
	 * This allows for dependency injection even though we don't control object creation.
	 */
	private function initServices() {
		if ( !$this->titleParser ) {
			$this->titleParser = MediaWikiServices::getInstance()->getTitleParser();
		}
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Initially from SpecialPrefixindex.php
	 * Modified with SpecialEditWatchlist.php
	 *
	 * Entry point : initialise variables and call subfunctions.
	 * @param string $par Becomes "FOO" when called like Special:WatchSubpages/FOO (default null)
	 */
	public function execute( $par ) {
		$this->initServices();
		$this->setHeaders();

		# Anons don't get a watchlist
		$this->requireLogin( 'watchlistanontext' );

		$out = $this->getOutput();

		$this->checkPermissions();
		$this->checkReadOnly();

		$this->outputHeader();
		$out->addModuleStyles( 'mediawiki.special' );

		# GET values
		$request = $this->getRequest();
		$prefix = $request->getVal( 'prefix', '' );
		$ns = $request->getIntOrNull( 'namespace' );
		$namespace = (int)$ns; // if no namespace given, use 0 (NS_MAIN).
		$this->hideRedirects = $request->getBool( 'hideredirects', $this->hideRedirects );

		$namespaces = MediaWikiServices::getInstance()->getContentLanguage()->getNamespaces();
		$out->setPageTitle( $this->msg( 'watchsubpages' ) );

		$showme = '';
		if ( $par !== null ) {
			$showme = $par;
		} elseif ( $prefix != '' ) {
			$showme = $prefix;
		}

		if ( $showme != '' ) {
			$this->showPrefixChunk( $namespace, $showme );
		} else {
			$out->addHTML( $this->namespacePrefixForm( $namespace, null ) );
		}
	}

	/**
	 * HTML for the top form
	 *
	 * Initially from SpecialPrefixindex.php
	 *
	 * @param int $namespace A namespace constant (default NS_MAIN).
	 * @param string $from DbKey we are starting listing at.
	 * @return string
	 */
	protected function namespacePrefixForm( $namespace = NS_MAIN, $from = '' ) {
		$formDescriptor = [
			'prefix' => [
				'label-message' => 'watchsubpagesprefix',
				'name' => 'prefix',
				'id' => 'nsfrom',
				'type' => 'text',
				'size' => 30,
				'default' => str_replace( '_', ' ', $from ),
			],
			'namespace' => [
				'type' => 'namespaceselect',
				'name' => 'namespace',
				'id' => 'namespace',
				'label-message' => 'namespace',
				'all' => null,
				'default' => $namespace,
			],
			'hidedirects' => [
				'class' => 'HTMLCheckField',
				'name' => 'hideredirects',
				'label-message' => 'allpages-hide-redirects',
				'selected' => $this->hideRedirects,
			],
		];

		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $this->getPageTitle() ); // Remove subpage
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $context );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'watchsubpages' )
			->setSubmitTextMsg( 'prefixindex-submit' );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * Initially from SpecialPrefixindex.php showPrefixChunk
	 * Modified with SpecialEditWatchlist.php executeViewEditWatchlist
	 *
	 * @param int $namespace
	 * @param string $prefix
	 */
	protected function showPrefixChunk( $namespace, $prefix ) {
		$prefixList = $this->getNamespaceKeyAndText( $namespace, $prefix );
		$namespaces = MediaWikiServices::getInstance()->getContentLanguage()->getNamespaces();
		$res = null;
		$out = $this->getOutput();
		$showPrefixForm = true;

		if ( !$prefixList ) {
			$out->prependHTML( $this->msg( 'allpagesbadtitle' )->parseAsBlock() );
		} elseif ( !array_key_exists( $namespace, $namespaces ) ) {
			// Show errormessage and reset to NS_MAIN
			$out->prependHTML( $this->msg( 'allpages-bad-ns', $namespace )->parse() );
			$namespace = NS_MAIN;
		} else {
			list( $namespace, $prefixKey, $prefix ) = $prefixList;

			$dbr = wfGetDB( DB_REPLICA );

			$conds = [
				'page_namespace' => $namespace,
				'page_title' . $dbr->buildLike( $prefixKey . '/', $dbr->anyString() ),
			];

			if ( $this->hideRedirects ) {
				$conds['page_is_redirect'] = 0;
			}

			$res = $dbr->select( 'page',
				array_merge(
					[ 'page_namespace', 'page_title' ],
					LinkCache::getSelectFields()
				),
				$conds,
				__METHOD__,
				[
					'ORDER BY' => 'page_title',
					'USE INDEX' => 'name_title',
				]
			);

			if ( $res->numRows() > 0 ) {
				foreach ( $res as $row ) {
					$pages[] = $row->page_title;
				}

				$form = $this->getNormalForm( $namespace, $prefix, $pages );
				if ( $form->show() ) {
					$showPrefixForm = false;
					$out->addHTML( $this->successMessage );
					$out->addReturnTo( SpecialPage::getTitleFor( 'WatchSubpages', $prefix ) );
				}
			}
		}

		if ( $showPrefixForm ) {
			$out->prependHTML( $this->namespacePrefixForm( $namespace, $prefix ) );
		}
	}

	/**
	 * Get the standard watchlist editing form
	 *
	 * Initially from SpecialEditWatchlist.php
	 *
	 * @param int $namespace
	 * @param string $prefix
	 * @param array $pages Array of strings
	 * @return HTMLForm
	 */
	protected function getNormalForm( $namespace, $prefix, $pages ) {
		$fields = [];
		$options = [];
		$defaults = [];

		$fields['prefix'] = [
				'type' => 'hidden',
				'name' => 'prefix',
				'default' => $prefix
		];

		$fields['namespace'] = [
				'type' => 'hidden',
				'name' => 'namespace',
				'default' => $namespace
		];

		foreach ( $pages as $dbkey ) {
			$title = Title::makeTitleSafe( $namespace, $dbkey );

			$text = $this->buildRemoveLine( $title );
			$options[$text] = $title->getPrefixedText();
			$defaults[] = $title->getPrefixedText();
		}

		// checkTitle can filter some options out, avoid empty sections
		if ( count( $options ) > 0 ) {
			$fields['Titles'] = [
				'class' => EditWatchlistCheckboxSeriesField::class,
				'options' => $options,
				'default' => $defaults,
				'section' => "ns$namespace",
			];
		}

		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $this->getPageTitle() ); // Remove subpage
		$form = new EditWatchlistNormalHTMLForm( $fields, $context );
		$form->setSubmitTextMsg( 'watchsubpages-submit' );
		$form->setSubmitDestructive();
		# Used message keys:
		# 'accesskey-watchsubpages-submit', 'tooltip-watchsubpages-submit'
		$form->setSubmitTooltip( 'watchsubpages-submit' );
		$form->setWrapperLegendMsg( 'watchsubpages-legend' );
		$form->addHeaderText( $this->msg( 'watchsubpages-explain' )->parse() );
		$form->setSubmitCallback( [ $this, 'submitRaw' ] );

		return $form;
	}

	/**
	 * Unmodified from SpecialAllpages.php
	 *
	 * @param int $ns The namespace of the article
	 * @param string $text The name of the article
	 * @return array|null [ int namespace, string dbkey, string pagename ] or null on error
	 */
	protected function getNamespaceKeyAndText( $ns, $text ) {
		if ( $text == '' ) {
			# shortcut for common case
			return [ $ns, '', '' ];
		}

		$t = Title::makeTitleSafe( $ns, $text );
		if ( $t && $t->isLocal() ) {
			return [ $t->getNamespace(), $t->getDBkey(), $t->getText() ];
		} elseif ( $t ) {
			return null;
		}

		# try again, in case the problem was an empty pagename
		$text = preg_replace( '/(#|$)/', 'X$1', $text );
		$t = Title::makeTitleSafe( $ns, $text );
		if ( $t && $t->isLocal() ) {
			return [ $t->getNamespace(), '', '' ];
		} else {
			return null;
		}
	}

	/**
	 * Build the label for a checkbox, with a link to the title, and various additional bits
	 *
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param Title $title
	 * @return string
	 */
	private function buildRemoveLine( $title ) {
		$linkRenderer = $this->getLinkRenderer();
		$link = $linkRenderer->makeLink( $title );

		$tools = [];
		$tools['talk'] = $linkRenderer->makeLink(
			$title->getTalkPage(),
			$this->msg( 'talkpagelinktext' )->text()
		);

		if ( $title->exists() ) {
			$tools['history'] = $linkRenderer->makeKnownLink(
				$title,
				$this->msg( 'history_small' )->text(),
				[],
				[ 'action' => 'history' ]
			);
		}

		if ( $title->getNamespace() == NS_USER && !$title->isSubpage() ) {
			$tools['contributions'] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'Contributions', $title->getText() ),
				$this->msg( 'contribslink' )->text()
			);
		}

		Hooks::run(
			'WatchlistEditorBuildRemoveLine',
			[ &$tools, $title, $title->isRedirect(), $this->getSkin(), &$link ]
		);

		if ( $title->isRedirect() ) {
			// Linker already makes class mw-redirect, so this is redundant
			$link = '<span class="watchlistredir">' . $link . '</span>';
		}

		return $link . ' ' .
			$this->msg( 'parentheses' )->rawParams( $this->getLanguage()->pipeList( $tools ) )->escaped();
	}

	/**
	 * Initially from SpecialEditWatchlist.php
	 *
	 * @param array $data
	 * @return true
	 */
	public function submitRaw( $data ) {
		$current = $this->getWatchlist();

		$toWatch = array_diff( $data['Titles'], $current );
		$this->watchTitles( $toWatch );
		$this->getUser()->invalidateCache();

		if ( count( $toWatch ) > 0 ) {
			$this->successMessage = $this->msg( 'watchlistedit-raw-done' )->parse();
		} else {
			$this->successMessage = $this->msg( 'watchsubpages-nochanges' )->parse();
		}

		if ( count( $toWatch ) > 0 ) {
			$this->successMessage .= ' ' . $this->msg( 'watchlistedit-raw-added' )
				->numParams( count( $toWatch ) )->parse();
			$this->showTitles( $toWatch, $this->successMessage );
		}

		return true;
	}

	/**
	 * Prepare a list of titles on a user's watchlist (excluding talk pages)
	 * and return an array of (prefixed) strings
	 *
	 * Initially from SpecialEditWatchlist.php
	 * Remove cleanupWatchlist
	 *
	 * @return array
	 */
	private function getWatchlist() {
		$list = [];

		$watchedItems = MediaWikiServices::getInstance()->getWatchedItemStore()->getWatchedItemsForUser(
			$this->getUser(),
			[ 'forWrite' => $this->getRequest()->wasPosted() ]
		);

		if ( $watchedItems ) {
			/** @var Title[] $titles */
			$titles = [];
			foreach ( $watchedItems as $watchedItem ) {
				$namespace = $watchedItem->getLinkTarget()->getNamespace();
				$dbKey = $watchedItem->getLinkTarget()->getDBkey();
				$title = Title::makeTitleSafe( $namespace, $dbKey );

				if ( $this->checkTitle( $title, $namespace, $dbKey )
					&& !$title->isTalkPage()
				) {
					$titles[] = $title;
				}
			}

			MediaWikiServices::getInstance()->getGenderCache()->doTitlesArray( $titles );

			foreach ( $titles as $title ) {
				$list[] = $title->getPrefixedText();
			}
		}

		return $list;
	}

	/**
	 * Validates watchlist entry
	 *
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param Title $title
	 * @param int $namespace
	 * @param string $dbKey
	 * @return bool Whether this item is valid
	 */
	private function checkTitle( $title, $namespace, $dbKey ) {
		if ( $title
			&& ( $title->isExternal()
				|| $title->getNamespace() < 0
			)
		) {
			$title = false; // unrecoverable
		}

		if ( !$title
			|| $title->getNamespace() != $namespace
			|| $title->getDBkey() != $dbKey
		) {
			$this->badItems[] = [ $title, $namespace, $dbKey ];
		}

		return (bool)$title;
	}

	/**
	 * Add a list of targets to a user's watchlist
	 *
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param string[]|LinkTarget[] $targets
	 * @return bool
	 * @throws FatalError
	 * @throws MWException
	 */
	private function watchTitles( array $targets ) {
		return MediaWikiServices::getInstance()->getWatchedItemStore()
			->addWatchBatchForUser( $this->getUser(), $this->getExpandedTargets( $targets ) )
			&& $this->runWatchUnwatchCompleteHook( 'Watch', $targets );
	}

	/**
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param string $action
	 *   Can be "Watch" or "Unwatch"
	 * @param string[]|LinkTarget[] $targets
	 * @return bool
	 * @throws FatalError
	 * @throws MWException
	 */
	private function runWatchUnwatchCompleteHook( $action, $targets ) {
		foreach ( $targets as $target ) {
			$title = $target instanceof TitleValue ?
				Title::newFromTitleValue( $target ) :
				Title::newFromText( $target );
			$page = WikiPage::factory( $title );
			Hooks::run( $action . 'ArticleComplete', [ $this->getUser(), &$page ] );
		}
		return true;
	}

	/**
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param string[]|LinkTarget[] $targets
	 * @return TitleValue[]
	 */
	private function getExpandedTargets( array $targets ) {
		$expandedTargets = [];
		$services = MediaWikiServices::getInstance();
		foreach ( $targets as $target ) {
			if ( !$target instanceof LinkTarget ) {
				try {
					$target = $this->titleParser->parseTitle( $target, NS_MAIN );
				}
				catch ( MalformedTitleException $e ) {
					continue;
				}
			}

			$ns = $target->getNamespace();
			$dbKey = $target->getDBkey();
			$expandedTargets[] =
				new TitleValue( $services->getNamespaceInfo()->getSubject( $ns ), $dbKey );
			$expandedTargets[] =
				new TitleValue( $services->getNamespaceInfo()->getTalk( $ns ), $dbKey );
		}
		return $expandedTargets;
	}

	/**
	 * Print out a list of linked titles
	 *
	 * $titles can be an array of strings or Title objects; the former
	 * is preferred, since Titles are very memory-heavy
	 *
	 * Initially from SpecialEditWatchlist.php
	 * Remove 100 page limit
	 *
	 * @param array $titles Array of strings, or Title objects
	 * @param string &$output
	 */
	private function showTitles( $titles, &$output ) {
		$talk = $this->msg( 'talkpagelinktext' )->text();
		// Do a batch existence check
		$batch = new LinkBatch();
		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}

			if ( $title instanceof Title ) {
				$batch->addObj( $title );
				$batch->addObj( $title->getTalkPage() );
			}
		}

		$batch->execute();

		// Print out the list
		$output .= "<ul>\n";

		$linkRenderer = $this->getLinkRenderer();
		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}

			if ( $title instanceof Title ) {
				$output .= '<li>' .
					$linkRenderer->makeLink( $title ) . ' ' .
					$this->msg( 'parentheses' )->rawParams(
						$linkRenderer->makeLink( $title->getTalkPage(), $talk )
					)->escaped() .
					"</li>\n";
			}
		}

		$output .= "</ul>\n";
	}

	/**
	 * Return an array of subpages beginning with $search that this special page will accept.
	 *
	 * Unmodified from SpecialPrefixIndex.php
	 *
	 * @param string $search Prefix to search for
	 * @param int $limit Maximum number of results to return (usually 10)
	 * @param int $offset Number of results to skip (usually 0)
	 * @return string[] Matching subpages
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		return $this->prefixSearchString( $search, $limit, $offset );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
