<?php
/**
 *
 * Created on January 3rd, 2013
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

class ApiImageRotate extends ApiBase {
	private $mPageSet = null;

	/**
	 * Add all items from $values into the result
	 * @param array $result Output
	 * @param array $values Values to add
	 * @param string $flag The name of the boolean flag to mark this element
	 * @param string $name If given, name of the value
	 */
	private static function addValues( array &$result, $values, $flag = null, $name = null ) {
		foreach ( $values as $val ) {
			if ( $val instanceof Title ) {
				$v = array();
				ApiQueryBase::addTitleInfo( $v, $val );
			} elseif ( $name !== null ) {
				$v = array( $name => $val );
			} else {
				$v = $val;
			}
			if ( $flag !== null ) {
				$v[$flag] = '';
			}
			$result[] = $v;
		}
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$rotation = $params['rotation'];

		$this->getResult()->beginContinuation( $params['continue'], array(), array() );

		$pageSet = $this->getPageSet();
		$pageSet->execute();

		$result = array();

		self::addValues( $result, $pageSet->getInvalidTitles(), 'invalid', 'title' );
		self::addValues( $result, $pageSet->getSpecialTitles(), 'special', 'title' );
		self::addValues( $result, $pageSet->getMissingPageIDs(), 'missing', 'pageid' );
		self::addValues( $result, $pageSet->getMissingRevisionIDs(), 'missing', 'revid' );
		self::addValues( $result, $pageSet->getInterwikiTitlesAsResult() );

		foreach ( $pageSet->getTitles() as $title ) {
			$r = array();
			$r['id'] = $title->getArticleID();
			ApiQueryBase::addTitleInfo( $r, $title );
			if ( !$title->exists() ) {
				$r['missing'] = '';
			}

			$file = wfFindFile( $title );
			if ( !$file ) {
				$r['result'] = 'Failure';
				$r['errormessage'] = 'File does not exist';
				$result[] = $r;
				continue;
			}
			$handler = $file->getHandler();
			if ( !$handler || !$handler->canRotate() ) {
				$r['result'] = 'Failure';
				$r['errormessage'] = 'File type cannot be rotated';
				$result[] = $r;
				continue;
			}

			// Check whether we're allowed to rotate this file
			$permError = $this->checkPermissions( $this->getUser(), $file->getTitle() );
			if ( $permError !== null ) {
				$r['result'] = 'Failure';
				$r['errormessage'] = $permError;
				$result[] = $r;
				continue;
			}

			$srcPath = $file->getLocalRefPath();
			if ( $srcPath === false ) {
				$r['result'] = 'Failure';
				$r['errormessage'] = 'Cannot get local file path';
				$result[] = $r;
				continue;
			}
			$ext = strtolower( pathinfo( "$srcPath", PATHINFO_EXTENSION ) );
			$tmpFile = TempFSFile::factory( 'rotate_', $ext );
			$dstPath = $tmpFile->getPath();
			$err = $handler->rotate( $file, array(
				"srcPath" => $srcPath,
				"dstPath" => $dstPath,
				"rotation" => $rotation
			) );
			if ( !$err ) {
				$comment = wfMessage(
					'rotate-comment'
				)->numParams( $rotation )->inContentLanguage()->text();
				$status = $file->upload( $dstPath,
					$comment, $comment, 0, false, false, $this->getUser() );
				if ( $status->isGood() ) {
					$r['result'] = 'Success';
				} else {
					$r['result'] = 'Failure';
					$r['errormessage'] = $this->getResult()->convertStatusToArray( $status );
				}
			} else {
				$r['result'] = 'Failure';
				$r['errormessage'] = $err->toText();
			}
			$result[] = $r;
		}
		$apiResult = $this->getResult();
		$apiResult->setIndexedTagName( $result, 'page' );
		$apiResult->addValue( null, $this->getModuleName(), $result );
		$apiResult->endContinuation();
	}

	/**
	 * Get a cached instance of an ApiPageSet object
	 * @return ApiPageSet
	 */
	private function getPageSet() {
		if ( $this->mPageSet === null ) {
			$this->mPageSet = new ApiPageSet( $this, 0, NS_FILE );
		}

		return $this->mPageSet;
	}

	/**
	 * Checks that the user has permissions to perform rotations.
	 * @param User $user The user to check
	 * @param Title $title
	 * @return string|null Permission error message, or null if there is no error
	 */
	protected function checkPermissions( $user, $title ) {
		$permissionErrors = array_merge(
			$title->getUserPermissionsErrors( 'edit', $user ),
			$title->getUserPermissionsErrors( 'upload', $user )
		);

		if ( $permissionErrors ) {
			// Just return the first error
			$msg = $this->parseMsg( $permissionErrors[0] );

			return $msg['info'];
		}

		return null;
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams( $flags = 0 ) {
		$result = array(
			'rotation' => array(
				ApiBase::PARAM_TYPE => array( '90', '180', '270' ),
				ApiBase::PARAM_REQUIRED => true
			),
			'continue' => array(
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			),
		);
		if ( $flags ) {
			$result += $this->getPageSet()->getFinalParams( $flags );
		}

		return $result;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getExamplesMessages() {
		return array(
			'action=imagerotate&titles=File:Example.jpg&rotation=90&token=123ABC'
				=> 'apihelp-imagerotate-example-simple',
			'action=imagerotate&generator=categorymembers&gcmtitle=Category:Flip&gcmtype=file&' .
				'rotation=180&token=123ABC'
				=> 'apihelp-imagerotate-example-generator',
		);
	}
}
