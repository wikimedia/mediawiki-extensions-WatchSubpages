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
		parent::__construct( 'Watchsubpages', 'watchsubpages' );
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

		$out = $this->getOutput();

		# Anons don't get a watchlist
		if ( $this->getUser()->isAnon() ) {
			$out->setPageTitle( $this->msg( 'watchnologin' ) );
			$llink = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Userlogin' ),
				$this->msg( 'loginreqlink' )->escaped(),
				array(),
				array( 'returnto' => $this->getTitle()->getPrefixedText() )
			);
			$out->addHTML( $this->msg( 'watchlistanontext' )->rawParams( $llink )->parse() );

			return;
		}

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

		$nsForm = $this->namespacePrefixForm( $namespace, $showme );
		$this->toc = Xml::openElement( 'table', array( 'id' => 'mw-prefixindex-nav-table' ) ) .
			'<tr>
				<td>' .
			$nsForm .
			'</td>
			<td id="mw-prefixindex-nav-form" class="mw-prefixindex-nav">';
		$this->toc .= "</td></tr>" .
			Xml::closeElement( 'table' );

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
	 * @param $namespace Integer: a namespace constant (default NS_MAIN).
	 * @param string $from dbKey we are starting listing at.
	 * @return string
	 */
	protected function namespacePrefixForm( $namespace = NS_MAIN, $from = '' ) {
		global $wgScript;

		$out = Xml::openElement( 'div', array( 'class' => 'namespaceoptions' ) );
		$out .= Xml::openElement( 'form', array( 'method' => 'get', 'action' => $wgScript ) );
		$out .= Html::hidden( 'title', $this->getTitle()->getPrefixedText() );
		$out .= Xml::openElement( 'fieldset' );
		$out .= Xml::element( 'legend', null, $this->msg( 'watchsubpages' )->text() );
		$out .= Xml::openElement( 'table', array( 'id' => 'nsselect', 'class' => 'allpages' ) );
		$out .= "<tr>
				<td class='mw-label'>" .
			Xml::label( $this->msg( 'watchsubpagesprefix' )->text(), 'nsfrom' ) .
			"</td>
				<td class='mw-input'>" .
			Xml::input( 'prefix', 30, str_replace( '_', ' ', $from ), array( 'id' => 'nsfrom' ) ) .
			"</td>
			</tr>
			<tr>
			<td class='mw-label'>" .
			Xml::label( $this->msg( 'namespace' )->text(), 'namespace' ) .
			"</td>
				<td class='mw-input'>" .
			Html::namespaceSelector( array(
				'selected' => $namespace,
			), array(
				'name' => 'namespace',
				'id' => 'namespace',
				'class' => 'namespaceselector',
			) ) .
			Xml::checkLabel(
				$this->msg( 'allpages-hide-redirects' )->text(),
				'hideredirects',
				'hideredirects',
				$this->hideRedirects
			) . ' ' .
			Xml::submitButton( $this->msg( 'allpagessubmit' )->text() ) .
			"</td>
			</tr>";
		$out .= Xml::closeElement( 'table' );
		$out .= Xml::closeElement( 'fieldset' );
		$out .= Xml::closeElement( 'form' );
		$out .= Xml::closeElement( 'div' );

		return $out;
	}

	/**
	 * Get the watchlist editing form
	 *
	 * Initially from SpecialEditWatchlist.php
	 * Modified with SpecialEditWatchlist.php getWatchlistInfo
	 * Modified with SpecialPrefixindex.php showPrefixChunk
	 * Modified with SpecialPrefixindex.php execute
	 *
	 * @param $namespace Integer: a namespace constant (default NS_MAIN).
	 * @param string $prefix dbKey we are starting listing at.
	 * @return HTMLForm
	 */
	protected function getNormalForm( $namespace = NS_MAIN, $prefix ) {
		global $wgContLang;

		$prefixList = $this->getNamespaceKeyAndText( $namespace, $prefix );
		$namespaces = $wgContLang->getNamespaces();

		if ( !$prefixList ) {
			$this->toc .= $this->msg( 'allpagesbadtitle' )->parseAsBlock();
		} elseif ( !in_array( $namespace, array_keys( $namespaces ) ) ) {
			// Show errormessage and reset to NS_MAIN
			$this->toc .= $this->msg( 'allpages-bad-ns', $namespace )->parse();
			$namespace = NS_MAIN;
		}

		list( $namespace, $prefixKey, $prefix ) = $prefixList;

		$dbr = wfGetDB( DB_SLAVE );

		$conds = array(
			'page_namespace' => $namespace,
			'page_title' . $dbr->buildLike( $prefixKey . '/' , $dbr->anyString() ),
		);

		if ( $this->hideRedirects ) {
			$conds['page_is_redirect'] = 0;
		}

		$res = $dbr->select( 'page',
			array( 'page_namespace', 'page_title', 'page_is_redirect' ),
			$conds,
			__METHOD__,
			array(
				'ORDER BY' => 'page_title',
				'USE INDEX' => 'name_title',
			)
		);

		$pages = array();

		if ( $res->numRows() > 0 ) {
			$lb = new LinkBatch();

			foreach ( $res as $row ) {
				$lb->add( $row->page_namespace, $row->page_title );
				$pages[$row->page_title] = 1;
			}

			$lb->execute();
		}

		$dispNamespace = MWNamespace::getSubject( $namespace );

		$fields = array();

		$fields['prefix'] =  array(
				'type' => 'hidden',
				'name' => 'prefix',
				'default' => $prefix
		);

		$fields['namespace'] =  array(
				'type' => 'hidden',
				'name' => 'namespace',
				'default' => $namespace
		);

		$fields['Titles'] = array(
			'class' => 'EditWatchlistCheckboxSeriesField',
			'options' => array(),
			'section' => "ns$dispNamespace",
		);

		foreach ( array_keys( $pages ) as $dbkey ) {
			$title = Title::makeTitleSafe( $dispNamespace, $dbkey );

			$text = $this->buildRemoveLine( $title );
			$fields['Titles']['options'][$text] = $title->getPrefixedText();
			$fields['Titles']['default'][] = $title->getPrefixedText();
		}

		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $this->getTitle() ); // Remove subpage
		$form = new EditWatchlistNormalHTMLForm( $fields, $context );
		$form->setSubmitTextMsg( 'watchsubpages-submit' );
		# Used message keys: 'accesskey-watchsubpages-submit', 'tooltip-watchsubpages-submit'
		$form->setSubmitTooltip( 'watchsubpages-submit' );
		$form->setWrapperLegendMsg( 'watchsubpages-legend' );
		$form->addHeaderText( $this->msg( 'watchsubpages-explain' )->parse() );
		$form->setSubmitCallback( array( $this, 'submitRaw' ) );

		return $form;
	}

	/**
	 * Unmodified from SpecialAllpages.php
	 *
	 * @param $ns Integer: the namespace of the article
	 * @param string $text the name of the article
	 * @return array( int namespace, string dbkey, string pagename ) or NULL on error
	 */
	protected function getNamespaceKeyAndText( $ns, $text ) {
		if ( $text == '' ) {
			# shortcut for common case
			return array( $ns, '', '' );
		}

		$t = Title::makeTitleSafe( $ns, $text );
		if ( $t && $t->isLocal() ) {
			return array( $t->getNamespace(), $t->getDBkey(), $t->getText() );
		} elseif ( $t ) {
			return null;
		}

		# try again, in case the problem was an empty pagename
		$text = preg_replace( '/(#|$)/', 'X$1', $text );
		$t = Title::makeTitleSafe( $ns, $text );
		if ( $t && $t->isLocal() ) {
			return array( $t->getNamespace(), '', '' );
		} else {
			return null;
		}
	}

	/**
	 * Build the label for a checkbox, with a link to the title, and various additional bits
	 *
	 * Unmodified from SpecialEditWatchlist.php
	 *
	 * @param $title Title
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
				array(),
				array( 'action' => 'history' )
			);
		}

		if ( $title->getNamespace() == NS_USER && !$title->isSubpage() ) {
			$tools[] = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Contributions', $title->getText() ),
				$this->msg( 'contributions' )->escaped()
			);
		}

		wfRunHooks( 'WatchlistEditorBuildRemoveLine', array( &$tools, $title, $title->isRedirect(), $this->getSkin() ) );

		return $link . " (" . $this->getLanguage()->pipeList( $tools ) . ")";
	}

	/**
	 * Initially from SpecialEditWatchlist.php
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
			$this->successMessage .= ' ' . $this->msg( 'watchlistedit-raw-added'
			)->numParams( count( $toWatch ) )->parse();
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
		$list = array();
		$dbr = wfGetDB( DB_MASTER );

		$res = $dbr->select(
			'watchlist',
			array(
				'wl_namespace', 'wl_title'
			), array(
				'wl_user' => $this->getUser()->getId(),
			),
			__METHOD__
		);

		if ( $res->numRows() > 0 ) {
			$titles = array();
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
		$rows = array();

		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}

			if ( $title instanceof Title ) {
				$rows[] = array(
					'wl_user' => $this->getUser()->getId(),
					'wl_namespace' => MWNamespace::getSubject( $title->getNamespace() ),
					'wl_title' => $title->getDBkey(),
					'wl_notificationtimestamp' => null,
				);
				$rows[] = array(
					'wl_user' => $this->getUser()->getId(),
					'wl_namespace' => MWNamespace::getTalk( $title->getNamespace() ),
					'wl_title' => $title->getDBkey(),
					'wl_notificationtimestamp' => null,
				);
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
	 * @param array $titles of strings, or Title objects
	 * @param $output String
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
