<?php
/**
 * @file
 * @ingroup PF
 */

/**
 * Adds and handles the 'pfautocomplete' action to the MediaWiki API.
 *
 * @ingroup PF
 *
 * @author Sergey Chernyshev
 * @author Yaron Koren
 */
class PFAutocompleteAPI extends ApiBase {

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$substr = $params['substr'];
		$namespace = $params['namespace'];
		$property = $params['property'];
		$category = $params['category'];
		$concept = $params['concept'];
		$cargo_table = $params['cargo_table'];
		$cargo_field = $params['cargo_field'];
		$external_url = $params['external_url'];
		$baseprop = $params['baseprop'];
		$base_cargo_table = $params['base_cargo_table'];
		$base_cargo_field = $params['base_cargo_field'];
		$basevalue = $params['basevalue'];
		// $limit = $params['limit'];

		if ( is_null( $baseprop ) && is_null( $base_cargo_table ) && strlen( $substr ) == 0 ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( array( 'apierror-missingparam', 'substr' ), 'param_substr' );
			} else {
				$this->dieUsage( 'The substring must be specified', 'param_substr' );
			}
		}

		global $wgPageFormsUseDisplayTitle;
		$map = false;
		if ( !is_null( $baseprop ) ) {
			if ( !is_null( $property ) ) {
				$data = $this->getAllValuesForProperty( $property, null, $baseprop, $basevalue );
			}
		} elseif ( !is_null( $property ) ) {
			$data = $this->getAllValuesForProperty( $property, $substr );
		} elseif ( !is_null( $category ) ) {
			$data = PFValuesUtils::getAllPagesForCategory( $category, 3, $substr );
			$map = $wgPageFormsUseDisplayTitle;
			if ( $map ) {
				$data = PFValuesUtils::disambiguateLabels( $data );
			}
		} elseif ( !is_null( $concept ) ) {
			$data = PFValuesUtils::getAllPagesForConcept( $concept, $substr );
			$map = $wgPageFormsUseDisplayTitle;
			if ( $map ) {
				$data = PFValuesUtils::disambiguateLabels( $data );
			}
		} elseif ( !is_null( $cargo_table ) && !is_null( $cargo_field ) ) {
			$data = self::getAllValuesForCargoField( $cargo_table, $cargo_field, $substr, $base_cargo_table, $base_cargo_field, $basevalue );
		} elseif ( !is_null( $namespace ) ) {
			$data = PFValuesUtils::getAllPagesForNamespace( $namespace, $substr );
			$map = $wgPageFormsUseDisplayTitle;
		} elseif ( !is_null( $external_url ) ) {
			$data = PFValuesUtils::getValuesFromExternalURL( $external_url, $substr );
		} else {
			$data = array();
		}

		// If we got back an error message, exit with that message.
		if ( !is_array( $data ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				if ( !$data instanceof Message ) {
					$data = ApiMessage::create( new RawMessage( '$1', array( $data ) ), 'unknownerror' );
				}
				$this->dieWithError( $data );
			} else {
				$code = 'unknownerror';
				if ( $data instanceof Message ) {
					$code = $data instanceof IApiMessage ? $data->getApiCode() : $data->getKey();
					$data = $data->inLanguage( 'en' )->useDatabase( false )->text();
				}
				$this->dieUsage( $data, $code );
			}
		}

		// to prevent JS parsing problems, display should be the same
		// even if there are no results
		/*
		if ( count( $data ) <= 0 ) {
			return;
		}
		*/

		// Format data as the API requires it - this is not needed
		// for "values from url", where the data is already formatted
		// correctly.
		if ( is_null( $external_url ) ) {
			$formattedData = array();
			foreach ( $data as $index => $value ) {
				if ( $map ) {
					$formattedData[] = array( 'title' => $index, 'displaytitle' => $value );
				} else {
					$formattedData[] = array( 'title' => $value );
				}
			}
		} else {
			$formattedData = $data;
		}

		// Set top-level elements.
		$result = $this->getResult();
		$result->setIndexedTagName( $formattedData, 'p' );
		$result->addValue( null, $this->getModuleName(), $formattedData );
	}

	protected function getAllowedParams() {
		return array(
			'limit' => array(
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			),
			'substr' => null,
			'property' => null,
			'category' => null,
			'concept' => null,
			'cargo_table' => null,
			'cargo_field' => null,
			'namespace' => null,
			'external_url' => null,
			'baseprop' => null,
			'base_cargo_table' => null,
			'base_cargo_field' => null,
			'basevalue' => null,
		);
	}

	protected function getParamDescription() {
		return array(
			'substr' => 'Search substring',
			'property' => 'Semantic property for which to search values',
			'category' => 'Category for which to search values',
			'concept' => 'Concept for which to search values',
			'namespace' => 'Namespace for which to search values',
			'external_url' => 'Alias for external URL from which to get values',
			'baseprop' => 'A previous property in the form to check against',
			'basevalue' => 'The value to check for the previous property',
			// 'limit' => 'Limit how many entries to return',
		);
	}

	protected function getDescription() {
		return 'Autocompletion call used by the Page Forms extension (https://www.mediawiki.org/Extension:Page_Forms)';
	}

	protected function getExamples() {
		return array(
			'api.php?action=pfautocomplete&substr=te',
			'api.php?action=pfautocomplete&substr=te&property=Has_author',
			'api.php?action=pfautocomplete&substr=te&category=Authors',
		);
	}

	private function getAllValuesForProperty(
		$property_name,
		$substring,
		$basePropertyName = null,
		$baseValue = null
	) {
		global $wgPageFormsMaxAutocompleteValues, $wgPageFormsCacheAutocompleteValues,
		$wgPageFormsAutocompleteCacheTimeout;
		global $smwgDefaultStore;

		if ( $smwgDefaultStore == null ) {
			$this->dieUsage( 'Semantic MediaWiki must be installed to query on "property"', 'param_property' );
		}

		$values = array();
		$db = wfGetDB( DB_REPLICA );
		$sqlOptions = array();
		$sqlOptions['LIMIT'] = $wgPageFormsMaxAutocompleteValues;

		if ( method_exists( 'SMW\DataValueFactory', 'newPropertyValueByLabel' ) ) {
			// SMW 3.0+
			$property = SMW\DataValueFactory::getInstance()->newPropertyValueByLabel( $property_name );
		} else {
			$property = SMWPropertyValue::makeUserProperty( $property_name );
		}
		$propertyHasTypePage = ( $property->getPropertyTypeID() == '_wpg' );
		$property_name = str_replace( ' ', '_', $property_name );
		$conditions = array( 'p_ids.smw_title' => $property_name );

		// Use cache if allowed
		if ( $wgPageFormsCacheAutocompleteValues ) {
			$cache = PFFormUtils::getFormCache();
			// Remove trailing whitespace to avoid unnecessary database selects
			$cacheKeyString = $property_name . '::' . rtrim( $substring );
			if ( !is_null( $basePropertyName ) ) {
				$cacheKeyString .= ',' . $basePropertyName . ',' . $baseValue;
			}
			$cacheKey = wfMemcKey( 'pf-autocomplete', md5( $cacheKeyString ) );
			$values = $cache->get( $cacheKey );

			if ( !empty( $values ) ) {
				// Return with results immediately
				return $values;
			}
		}

		if ( $propertyHasTypePage ) {
			$valueField = 'o_ids.smw_title';
			if ( $smwgDefaultStore === 'SMWSQLStore2' ) {
				$idsTable = $db->tableName( 'smw_ids' );
				$propsTable = $db->tableName( 'smw_rels2' );
			} else { // SMWSQLStore3 - also the backup for SMWSPARQLStore
				$idsTable = $db->tableName( 'smw_object_ids' );
				$propsTable = $db->tableName( 'smw_di_wikipage' );
			}
			$fromClause = "$propsTable p JOIN $idsTable p_ids ON p.p_id = p_ids.smw_id JOIN $idsTable o_ids ON p.o_id = o_ids.smw_id";
		} else {
			if ( $smwgDefaultStore === 'SMWSQLStore2' ) {
				$valueField = 'p.value_xsd';
				$idsTable = $db->tableName( 'smw_ids' );
				$propsTable = $db->tableName( 'smw_atts2' );
			} else { // SMWSQLStore3 - also the backup for SMWSPARQLStore
				$valueField = 'p.o_hash';
				$idsTable = $db->tableName( 'smw_object_ids' );
				$propsTable = $db->tableName( 'smw_di_blob' );
			}
			$fromClause = "$propsTable p JOIN $idsTable p_ids ON p.p_id = p_ids.smw_id";
		}

		if ( !is_null( $basePropertyName ) ) {
			if ( method_exists( 'SMW\DataValueFactory', 'newPropertyValueByLabel' ) ) {
				$baseProperty = SMW\DataValueFactory::getInstance()->newPropertyValueByLabel( $basePropertyName );
			} else {
				// SMW 3.0+
				$baseProperty = SMWPropertyValue::makeUserProperty( $basePropertyName );
			}
			$basePropertyHasTypePage = ( $baseProperty->getPropertyTypeID() == '_wpg' );

			$basePropertyName = str_replace( ' ', '_', $basePropertyName );
			$conditions['base_p_ids.smw_title'] = $basePropertyName;
			if ( $basePropertyHasTypePage ) {
				if ( $smwgDefaultStore === 'SMWSQLStore2' ) {
					$idsTable = $db->tableName( 'smw_ids' );
					$propsTable = $db->tableName( 'smw_rels2' );
				} else {
					$idsTable = $db->tableName( 'smw_object_ids' );
					$propsTable = $db->tableName( 'smw_di_wikipage' );
				}
				$fromClause .= " JOIN $propsTable p_base ON p.s_id = p_base.s_id";
				$fromClause .= " JOIN $idsTable base_p_ids ON p_base.p_id = base_p_ids.smw_id JOIN $idsTable base_o_ids ON p_base.o_id = base_o_ids.smw_id";
				$baseValue = str_replace( ' ', '_', $baseValue );
				$conditions['base_o_ids.smw_title'] = $baseValue;
			} else {
				if ( $smwgDefaultStore === 'SMWSQLStore2' ) {
					$baseValueField = 'p_base.value_xsd';
					$idsTable = $db->tableName( 'smw_ids' );
					$propsTable = $db->tableName( 'smw_atts2' );
				} else {
					$baseValueField = 'p_base.o_hash';
					$idsTable = $db->tableName( 'smw_object_ids' );
					$propsTable = $db->tableName( 'smw_di_blob' );
				}
				$fromClause .= " JOIN $propsTable p_base ON p.s_id = p_base.s_id";
				$fromClause .= " JOIN $idsTable base_p_ids ON p_base.p_id = base_p_ids.smw_id";
				$conditions[$baseValueField] = $baseValue;
			}
		}

		if ( !is_null( $substring ) ) {
			// "Page" type property valeus are stored differently
			// in the DB, i.e. underlines instead of spaces.
			$conditions[] = PFValuesUtils::getSQLConditionForAutocompleteInColumn( $valueField, $substring, $propertyHasTypePage );
		}

		$sqlOptions['ORDER BY'] = $valueField;
		$res = $db->select( $fromClause, "DISTINCT $valueField",
			$conditions, __METHOD__, $sqlOptions );

		while ( $row = $db->fetchRow( $res ) ) {
			$values[] = str_replace( '_', ' ', array_shift( $row ) );
		}
		$db->freeResult( $res );

		if ( $wgPageFormsCacheAutocompleteValues ) {
			// Save to cache.
			$cache->set( $cacheKey, $values, $wgPageFormsAutocompleteCacheTimeout );
		}

		return $values;
	}

	private static function getAllValuesForCargoField( $cargoTable, $cargoField, $substring, $baseCargoTable = null, $baseCargoField = null, $baseValue = null ) {
		global $wgPageFormsMaxAutocompleteValues, $wgPageFormsCacheAutocompleteValues, $wgPageFormsAutocompleteCacheTimeout;
		global $wgPageFormsAutocompleteOnAllChars;

		$values = array();
		$tablesStr = $cargoTable;
		$fieldsStr = $cargoField;
		$joinOnStr = '';
		$whereStr = '';

		// Use cache if allowed
		if ( $wgPageFormsCacheAutocompleteValues ) {
			$cache = PFFormUtils::getFormCache();
			// Remove trailing whitespace to avoid unnecessary database selects
			$cacheKeyString = $cargoTable . '|' . $cargoField . '|' . rtrim( $substring );
			if ( !is_null( $baseCargoTable ) ) {
				$cacheKeyString .= '|' . $baseCargoTable . '|' . $baseCargoField . '|' . $baseValue;
			}
			$cacheKey = wfMemcKey( 'pf-autocomplete', md5( $cacheKeyString ) );
			$values = $cache->get( $cacheKey );

			if ( !empty( $values ) ) {
				// Return with results immediately
				return $values;
			}
		}

		if ( !is_null( $baseCargoTable ) && !is_null( $baseCargoField ) ) {
			if ( $baseCargoTable != $cargoTable ) {
				$tablesStr .= ", $baseCargoTable";
				$joinOnStr = "$cargoTable._pageName = $baseCargoTable._pageName";
			}
			$whereStr = "$baseCargoTable.$baseCargoField = \"$baseValue\"";
		}

		if ( !is_null( $substring ) ) {
			if ( $whereStr != '' ) {
				$whereStr .= " AND ";
			}
			$fieldIsList = self::cargoFieldIsList( $cargoTable, $cargoField );
			$operator = ( $fieldIsList ) ? "HOLDS LIKE" : "LIKE";
			if ( $wgPageFormsAutocompleteOnAllChars ) {
				$whereStr .= "($cargoField $operator \"%$substring%\")";
			} else {
				$whereStr .= "($cargoField $operator \"$substring%\" OR $cargoField $operator \"% $substring%\")";
			}
		}

		$sqlQuery = CargoSQLQuery::newFromValues(
			$tablesStr,
			$fieldsStr,
			$whereStr,
			$joinOnStr,
			$cargoField,
			$havingStr = null,
			$cargoField,
			$wgPageFormsMaxAutocompleteValues,
			$offsetStr = 0
		);
		$queryResults = $sqlQuery->run();

		if ( $cargoField[0] != '_' ) {
			$cargoFieldAlias = str_replace( '_', ' ', $cargoField );
		} else {
			$cargoFieldAlias = $cargoField;
		}

		foreach ( $queryResults as $row ) {
			// @TODO - this check should not be necessary.
			if ( ( $value = $row[$cargoFieldAlias] ) != '' ) {
				$values[] = $value;
			}
		}

		if ( $wgPageFormsCacheAutocompleteValues ) {
			// Save to cache.
			$cache->set( $cacheKey, $values, $wgPageFormsAutocompleteCacheTimeout );
		}

		return $values;
	}

	static function cargoFieldIsList( $cargoTable, $cargoField ) {
		// @TODO - this is duplicate work; the schema is retrieved
		// again when the CargoSQLQuery object is created. There should
		// be some way of avoiding that duplicate retrieval.
		$tableSchemas = CargoUtils::getTableSchemas( array( $cargoTable ) );
		if ( !array_key_exists( $cargoTable, $tableSchemas ) ) {
			return false;
		}
		$tableSchema = $tableSchemas[$cargoTable];
		if ( !array_key_exists( $cargoField, $tableSchema->mFieldDescriptions ) ) {
			return false;
		}
		$fieldDesc = $tableSchema->mFieldDescriptions[$cargoField];
		return $fieldDesc->mIsList;
	}

}
