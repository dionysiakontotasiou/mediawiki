<?php
/**
 *
 *
 * Created on Jul 9, 2009
 *
 * Copyright © 2009
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
 */

/**
 * Query module to enumerate change tags.
 *
 * @ingroup API
 */
class ApiQueryTags extends ApiQueryBase {

	/**
	 * @var ApiResult
	 */
	private $result;

	private $limit;
	private $fld_displayname = false, $fld_description = false,
		$fld_hitcount = false;

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'tg' );
	}

	public function execute() {
		$params = $this->extractRequestParams();

		$prop = array_flip( $params['prop'] );

		$this->fld_displayname = isset( $prop['displayname'] );
		$this->fld_description = isset( $prop['description'] );
		$this->fld_hitcount = isset( $prop['hitcount'] );

		$this->limit = $params['limit'];
		$this->result = $this->getResult();

		$this->addTables( 'change_tag' );
		$this->addFields( 'ct_tag' );

		$this->addFieldsIf( array( 'hitcount' => 'COUNT(*)' ), $this->fld_hitcount );

		$this->addOption( 'LIMIT', $this->limit + 1 );
		$this->addOption( 'GROUP BY', 'ct_tag' );
		$this->addWhereRange( 'ct_tag', 'newer', $params['continue'], null );

		$res = $this->select( __METHOD__ );

		$ok = true;

		foreach ( $res as $row ) {
			if ( !$ok ) {
				break;
			}
			$ok = $this->doTag( $row->ct_tag, $this->fld_hitcount ? $row->hitcount : 0 );
		}

		// include tags with no hits yet
		foreach ( ChangeTags::listDefinedTags() as $tag ) {
			if ( !$ok ) {
				break;
			}
			$ok = $this->doTag( $tag, 0 );
		}

		$this->result->setIndexedTagName_internal( array( 'query', $this->getModuleName() ), 'tag' );
	}

	private function doTag( $tagName, $hitcount ) {
		static $count = 0;
		static $doneTags = array();

		if ( in_array( $tagName, $doneTags ) ) {
			return true;
		}

		if ( ++$count > $this->limit ) {
			$this->setContinueEnumParameter( 'continue', $tagName );

			return false;
		}

		$tag = array();
		$tag['name'] = $tagName;

		if ( $this->fld_displayname ) {
			$tag['displayname'] = ChangeTags::tagDescription( $tagName );
		}

		if ( $this->fld_description ) {
			$msg = wfMessage( "tag-$tagName-description" );
			$tag['description'] = $msg->exists() ? $msg->text() : '';
		}

		if ( $this->fld_hitcount ) {
			$tag['hitcount'] = $hitcount;
		}

		$doneTags[] = $tagName;

		$fit = $this->result->addValue( array( 'query', $this->getModuleName() ), null, $tag );
		if ( !$fit ) {
			$this->setContinueEnumParameter( 'continue', $tagName );

			return false;
		}

		return true;
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function getAllowedParams() {
		return array(
			'continue' => array(
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			),
			'limit' => array(
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			),
			'prop' => array(
				ApiBase::PARAM_DFLT => 'name',
				ApiBase::PARAM_TYPE => array(
					'name',
					'displayname',
					'description',
					'hitcount'
				),
				ApiBase::PARAM_ISMULTI => true
			)
		);
	}

	public function getExamplesMessages() {
		return array(
			'action=query&list=tags&tgprop=displayname|description|hitcount'
				=> 'apihelp-query+tags-example-simple',
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/API:Tags';
	}
}
