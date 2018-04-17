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

	protected $toc;

	protected $hideRedirects = false;

	public function __construct() {
		parent::__construct( 'WatchSubpages', 'watchsubpages' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Main execution point
	 *
	 * Initially from SpecialEditWatchlist.php
	 * Modified with SpecialPrefixindex.php
	 *
	 * @param string $par becomes "FOO" when called like Special:WatchSubpages/FOO (default null)
	 */
	public function execute( $par ) {
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

		$out->setPageTitle( $this->msg( 'watchsubpages' ) );

		$showme = '';
		if ( isset( $par ) ) {
			$showme = $par;
		} elseif ( $prefix != '' ) {
			$showme = $prefix;
		}

		$this->namespacePrefixForm( $namespace, $showme );

		$form = $this->getNormalForm( $namespace, $showme );
		if ( $showme != '' && $form->show() ) {
			$out->addHTML( $this->successMessage );
			$out->addReturnTo( SpecialPage::getTitleFor( 'WatchSubpages', $showme ) );
		} elseif ( $this->toc !== false ) {
			$out->prependHTML( $this->toc );
		}
	}

	/**
	 * HTML for the top form
	 *
	 * Initially from SpecialPrefixindex.php
	 *
	 * @param int $namespace a namespace constant (default NS_MAIN).
	 * @param string $from dbKey we are starting listing at.
	 * @return string
	 */
	protected function namespacePrefixForm( $namespace = NS_MAIN, $from = '' ) {
		$formDescriptor = [
			'textbox' => [
				'type' => 'text',
				'id' => 'nsfrom',
				'label' => $this->msg( 'watchsubpagesprefix' )->text(),
				'name' => 'prefix',
				'size' => 30,
				'value' => str_replace( '_', ' ', $from ),
			],
			'namespace' => [
				'type' => 'namespaceselect',
				'all' => null,
				'default' => 0,
				'id' => 'namespace',
				'label' => $this->msg( 'namespace' )->text(),
				'name' => 'namespace',
				'value' => $namespace,
			],
			'mycheck' => [
				'type' => 'check',
				'id' => 'hideredirects',
				'label' => $this->msg( 'allpages-hide-redirects' )->text(),
				'name' => 'hideredirects',
				'selected' => $this->hideRedirects,
			],
			'button' => [
				'type' => 'submit',
				'default' => $this->msg( 'allpagessubmit' )->text(),
				'flags' => [ 'primary' ],
				'name' => 'submit',
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'watchsubpages' )
			->suppressDefaultSubmit()
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * Get the watchlist editing form
	 *
	 * Initially from SpecialEditWatchlist.php
	 * Modified with SpecialEditWatchlist.php getWatchlistInfo
	 * Modified with SpecialPrefixindex.php showPrefixChunk
	 * Modified with SpecialPrefixindex.php execute
	 *
	 * @param int $namespace a namespace constant (default NS_MAIN).
	 * @param string $prefix dbKey we are starting listing at.
	 * @return HTMLForm
	 */
	protected function getNormalForm( $namespace = NS_MAIN, $prefix ) {
		global $wgContLang;

		$prefixList = $this->getNamespaceKeyAndText( $namespace, $prefix );
		$namespaces = $wgContLang->getNamespaces();

		if ( !$prefixList ) {
			$this->toc .= $this->msg( 'allpagesbadtitle' )->parseAsBlock();
		} elseif ( !array_key_exists( $namespace, $namespaces ) ) {
			// Show errormessage and reset to NS_MAIN
			$this->toc .= $this->msg( 'allpages-bad-ns', $namespace )->parse();
			$namespace = NS_MAIN;
		}

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
			[ 'page_namespace', 'page_title', 'page_is_redirect' ],
			$conds,
			__METHOD__,
			[
				'ORDER BY' => 'page_title',
				'USE INDEX' => 'name_title',
			]
		);

		$pages = [];

		if ( $res->numRows() > 0 ) {
			$lb = new LinkBatch();

			foreach ( $res as $row ) {
				$lb->add( $row->page_namespace, $row->page_title );
				$pages[$row->page_title] = 1;
			}

			$lb->execute();
		}

		$dispNamespace = MWNamespace::getSubject( $namespace );

		$fields = [];

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

		$fields['Titles'] = [
			'class' => 'EditWatchlistCheckboxSeriesField',
			'options' => [],
			'section' => "ns$dispNamespace",
		];

		foreach ( array_keys( $pages ) as $dbkey ) {
			$title = Title::makeTitleSafe( $dispNamespace, $dbkey );

			$text = $this->buildRemoveLine( $title );
			$fields['Titles']['options'][$text] = $title->getPrefixedText();
			$fields['Titles']['default'][] = $title->getPrefixedText();
		}

		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $this->getPageTitle() ); // Remove subpage
		$form = new EditWatchlistNormalHTMLForm( $fields, $context );
		$form->setSubmitTextMsg( 'watchsubpages-submit' );
		# Used message keys: 'accesskey-watchsubpages-submit', 'tooltip-watchsubpages-submit'
		$form->setSubmitTooltip( 'watchsubpages-submit' );
		$form->setWrapperLegendMsg( 'watchsubpages-legend' );
		$form->addHeaderText( $this->msg( 'watchsubpages-explain' )->parse() );
		$form->setSubmitCallback( [ $this, 'submitRaw' ] );

		return $form;
	}

	/**
	 * Unmodified from SpecialAllpages.php
	 *
	 * @param int $ns the namespace of the article
	 * @param string $text the name of the article
	 * @return array int namespace, string dbkey, string pagename ) or NULL on error
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
		$link = Linker::link( $title );

		if ( $title->isRedirect() ) {
			// Linker already makes class mw-redirect, so this is redundant
			$link = '<span class="watchlistredir">' . $link . '</span>';
		}

		$tools[] = Linker::link( $title->getTalkPage(), $this->msg( 'talkpagelinktext' )->escaped() );

		if ( $title->exists() ) {
			$tools[] = Linker::linkKnown(
				$title,
				$this->msg( 'history_short' )->escaped(),
				[],
				[ 'action' => 'history' ]
			);
		}

		if ( $title->getNamespace() == NS_USER && !$title->isSubpage() ) {
			$tools[] = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Contributions', $title->getText() ),
				$this->msg( 'contributions' )->escaped()
			);
		}

		Hooks::run( 'WatchlistEditorBuildRemoveLine', [ &$tools, $title, $title->isRedirect(), $this->getSkin() ] );

		return $link . " (" . $this->getLanguage()->pipeList( $tools ) . ")";
	}

	/**
	 * Initially from SpecialEditWatchlist.php
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
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @return array
	 */
	private function getWatchlist() {
		$list = [];
		$dbr = wfGetDB( DB_MASTER );

		$res = $dbr->select(
			'watchlist',
			[
				'wl_namespace', 'wl_title'
			], [
				'wl_user' => $this->getUser()->getId(),
			],
			__METHOD__
		);

		if ( $res->numRows() > 0 ) {
			$titles = [];
			foreach ( $res as $row ) {
				$title = Title::makeTitleSafe( $row->wl_namespace, $row->wl_title );
				if ( !$title->isTalkPage() ) {
					$titles[] = $title;
				}
			}
			$res->free();

			GenderCache::singleton()->doTitlesArray( $titles );

			foreach ( $titles as $title ) {
				$list[] = $title->getPrefixedText();
			}
		}

		return $list;
	}

	/**
	 * Add a list of titles to a user's watchlist
	 *
	 * $titles can be an array of strings or Title objects; the former
	 * is preferred, since Titles are very memory-heavy
	 *
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param array $titles of strings, or Title objects
	 */
	private function watchTitles( $titles ) {
		$dbw = wfGetDB( DB_MASTER );
		$rows = [];

		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}

			if ( $title instanceof Title ) {
				$rows[] = [
					'wl_user' => $this->getUser()->getId(),
					'wl_namespace' => MWNamespace::getSubject( $title->getNamespace() ),
					'wl_title' => $title->getDBkey(),
					'wl_notificationtimestamp' => null,
				];
				$rows[] = [
					'wl_user' => $this->getUser()->getId(),
					'wl_namespace' => MWNamespace::getTalk( $title->getNamespace() ),
					'wl_title' => $title->getDBkey(),
					'wl_notificationtimestamp' => null,
				];
			}
		}

		$dbw->insert( 'watchlist', $rows, __METHOD__, 'IGNORE' );
	}

	/**
	 * Print out a list of linked titles
	 *
	 * $titles can be an array of strings or Title objects; the former
	 * is preferred, since Titles are very memory-heavy
	 *
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param array $titles Array of strings, or Title objects
	 * @param string &$output
	 */
	private function showTitles( $titles, &$output ) {
		$talk = $this->msg( 'talkpagelinktext' )->escaped();
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

		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}

			if ( $title instanceof Title ) {
				$output .= "<li>"
					. Linker::link( $title )
					. ' (' . Linker::link( $title->getTalkPage(), $talk )
					. ")</li>\n";
			}
		}

		$output .= "</ul>\n";
	}

	protected function getGroupName() {
		return 'pages';
	}
}
