<?php
/**
 * CargoSQLQuery - a wrapper class around SQL queries, that also handles
 * the special Cargo keywords like "HOLDS" and "NEAR".
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoSQLQuery {

	private $mCargoDB;
	public $mTablesStr;
	public $mTableNames;
	public $mFieldsStr;
	public $mOrigWhereStr;
	public $mWhereStr;
	public $mJoinOnStr;
	public $mCargoJoinConds;
	public $mJoinConds;
	public $mAliasedFieldNames;
	public $mFieldStringAliases;
	public $mTableSchemas;
	public $mFieldDescriptions;
	public $mFieldTables;
	public $mGroupByStr;
	public $mHavingStr;
	public $mOrderByStr;
	public $mQueryLimit;
	public $mSearchTerms = array();

	function __construct() {
		$this->mCargoDB = CargoUtils::getDB();
	}

	/**
	 * This is newFromValues() instead of __construct() so that an
	 * object can be created without any values.
	 */
	public static function newFromValues( $tablesStr, $fieldsStr, $whereStr, $joinOnStr, $groupByStr,
		$havingStr, $orderByStr, $limitStr ) {
		global $wgCargoDefaultQueryLimit, $wgCargoMaxQueryLimit;

		self::validateValues( $tablesStr, $fieldsStr, $whereStr, $joinOnStr, $groupByStr,
			$havingStr, $orderByStr, $limitStr );

		$sqlQuery = new CargoSQLQuery();
		$sqlQuery->mCargoDB = CargoUtils::getDB();
		$sqlQuery->mTablesStr = $tablesStr;
		$sqlQuery->mTableNames = array_map( 'trim', explode( ',', $tablesStr ) );
		$sqlQuery->mFieldsStr = $fieldsStr;
		// This _decode() call is necessary because the "where="
		// clause can (and often does) include a call to {{PAGENAME}},
		// which HTML-encodes certain characters, notably single quotes.
		$sqlQuery->mOrigWhereStr = htmlspecialchars_decode( $whereStr, ENT_QUOTES );
		$sqlQuery->mWhereStr = htmlspecialchars_decode( $whereStr, ENT_QUOTES );
		$sqlQuery->mJoinOnStr = $joinOnStr;
		$sqlQuery->setCargoJoinConds( $joinOnStr );
		$sqlQuery->setAliasedFieldNames();
		$sqlQuery->mTableSchemas = CargoUtils::getTableSchemas( $sqlQuery->mTableNames );
		$sqlQuery->setOrderBy( $orderByStr );
		$sqlQuery->mGroupByStr = $groupByStr;
		$sqlQuery->mHavingStr = $havingStr;
		$sqlQuery->setDescriptionsForFields();
		$sqlQuery->handleVirtualFields();
		$sqlQuery->handleVirtualCoordinateFields();
		$sqlQuery->handleDateFields();
		$sqlQuery->handleSearchTextFields();
		$sqlQuery->setMWJoinConds();
		$sqlQuery->mQueryLimit = $wgCargoDefaultQueryLimit;
		if ( $limitStr != '' ) {
			$sqlQuery->mQueryLimit = min( $limitStr, $wgCargoMaxQueryLimit );
		}
		$sqlQuery->addTablePrefixesToAll();

		return $sqlQuery;
	}

	/**
	 * Throw an error if there are forbidden values in any of the
	 * #cargo_query parameters - some or all of them are potential
	 * security risks.
	 *
	 * It could be that, given the way #cargo_query is structured, only
	 * some of the parameters need to be checked for these strings,
	 * but we might as well validate all of them.
	 *
	 * The function CargoUtils::getTableSchemas() also does specific
	 * validation of the "tables" parameter, while this class's
	 * setDescriptionsForFields() does validation of the "fields="
	 * parameter.
	 */
	public static function validateValues( $tablesStr, $fieldsStr, $whereStr, $joinOnStr, $groupByStr,
		$havingStr, $orderByStr, $limitStr ) {

		// Remove quoted strings from "where" parameter, to avoid
		// unnecessary false positives from words like "from"
		// being included in string comparisons.
		// However, before we do that, check for certain strings that
		// shouldn't be in quote marks either.
		$whereStrRegexps = array(
			'/\-\-/' => '--',
			'/#/' => '#',
		);

		// HTML-decode the string - this is necessary if the query
		// contains a call to {{PAGENAME}} and the page name has any
		// special characters, because {{PAGENAME]] unfortunately
		// HTML-encodes the value, which leads to a '#' in the string.
		$decodedWhereStr = html_entity_decode( $whereStr, ENT_QUOTES );
		foreach ( $whereStrRegexps as $regexp => $displayString ) {
			if ( preg_match( $regexp, $decodedWhereStr ) ) {
				throw new MWException( "Error in \"where\" parameter: the string \"$displayString\" cannot be used within #cargo_query." );
			}
		}
		$noQuotesFieldsStr = CargoUtils::removeQuotedStrings( $fieldsStr );
		$noQuotesWhereStr = CargoUtils::removeQuotedStrings( $whereStr );
		$noQuotesJoinOnStr = CargoUtils::removeQuotedStrings( $joinOnStr );
		$noQuotesGroupByStr = CargoUtils::removeQuotedStrings( $groupByStr );
		$noQuotesHavingStr = CargoUtils::removeQuotedStrings( $havingStr );
		$noQuotesOrderByStr = CargoUtils::removeQuotedStrings( $orderByStr );

		$regexps = array(
			'/\bselect\b/i' => 'SELECT',
			'/\binto\b/i' => 'INTO',
			'/\bfrom\b/i' => 'FROM',
			'/\bunion\b/i' => 'UNION',
			'/;/' => ';',
			'/@/' => '@',
			'/\<\?/' => '<?',
			'/\-\-/' => '--',
			'/\/\*/' => '/*',
			'/#/' => '#',
		);
		foreach ( $regexps as $regexp => $displayString ) {
			if ( preg_match( $regexp, $tablesStr ) ||
				preg_match( $regexp, $noQuotesFieldsStr ) ||
				preg_match( $regexp, $noQuotesWhereStr ) ||
				preg_match( $regexp, $noQuotesJoinOnStr ) ||
				preg_match( $regexp, $noQuotesGroupByStr ) ||
				preg_match( $regexp, $noQuotesHavingStr ) ||
				preg_match( $regexp, $noQuotesOrderByStr ) ||
				preg_match( $regexp, $limitStr ) ) {
				throw new MWException( "Error: the string \"$displayString\" cannot be used within #cargo_query." );
			}
		}

		self::getAndValidateSQLFunctions( $noQuotesWhereStr );
		self::getAndValidateSQLFunctions( $noQuotesJoinOnStr );
		self::getAndValidateSQLFunctions( $noQuotesGroupByStr );
		self::getAndValidateSQLFunctions( $noQuotesHavingStr );
		self::getAndValidateSQLFunctions( $noQuotesOrderByStr );
		self::getAndValidateSQLFunctions( $limitStr );
	}

	/**
	 * Gets a mapping of original field name strings to their field name aliases
	 * as they appear in the query result
	 */
	function getAliasForFieldString( $fieldString ) {
		return $this->mFieldStringAliases[$fieldString];
	}

	/**
	 * Gets an array of field names and their aliases from the passed-in
	 * SQL fragment.
	 */
	function setAliasedFieldNames() {
		$this->mAliasedFieldNames = array();
		$fieldStrings = CargoUtils::smartSplit( ',', $this->mFieldsStr );
		// Default is "_pageName".
		if ( count( $fieldStrings ) == 0 ) {
			$fieldStrings[] = '_pageName';
		}

		// Quick error-checking: for now, just disallow "DISTINCT",
		// and require "GROUP BY" instead.
		foreach ( $fieldStrings as $i => $fieldString ) {
			if ( strtolower( substr( $fieldString, 0, 9 ) ) == 'distinct ' ) {
				throw new MWException( "Error: The DISTINCT keyword is not allowed by Cargo; "
				. "please use \"group by=\" instead." );
			}
		}

		// Because aliases are used as keys, we can't have more than
		// one blank alias - so replace blank aliases with the name
		// "Blank value X" - it will get replaced back before being
		// displayed.
		$blankAliasCount = 0;
		foreach ( $fieldStrings as $i => $fieldString ) {
			$fieldStringParts = CargoUtils::smartSplit( '=', $fieldString );
			if ( count( $fieldStringParts ) == 2 ) {
				$fieldName = trim( $fieldStringParts[0] );
				$alias = trim( $fieldStringParts[1] );
			} else {
				$fieldName = $fieldString;
				// Might as well change underscores to spaces
				// by default - but for regular field names,
				// not the special ones.
				// "Real" field = with the table name removed.
				if ( strpos( $fieldName, '.' ) !== false ) {
					list( $tableName, $realFieldName ) = explode( '.', $fieldName, 2 );
				} else {
					$realFieldName = $fieldName;
				}
				if ( $realFieldName[0] != '_' ) {
					$alias = str_replace( '_', ' ', $realFieldName );
				} else {
					$alias = $realFieldName;
				}
			}
			if ( empty( $alias ) ) {
				$blankAliasCount++;
				$alias = "Blank value $blankAliasCount";
			}
			$this->mAliasedFieldNames[$alias] = $fieldName;
			$this->mFieldStringAliases[$fieldString] = $alias;
		}
	}

	/**
	 * This does double duty: it both creates a "join conds" array
	 * from the string, and validates the set of join conditions
	 * based on the set of table names - making sure each table is
	 * joined.
	 *
	 * The "join conds" array created is not of the format that
	 * MediaWiki's database query() method requires - it is more
	 * structured and does not contain the necessary table prefixes yet.
	 */
	function setCargoJoinConds( $joinOnStr ) {
		// This string is needed for "deferred" queries.
		$this->mJoinOnStr = $joinOnStr;

		$this->mCargoJoinConds = array();

		if ( trim( $joinOnStr ) == '' ) {
			if ( count( $this->mTableNames ) > 1 ) {
				throw new MWException( "Error: join conditions must be set for tables." );
			}
			return;
		}

		$joinStrings = explode( ',', $joinOnStr );
		foreach ( $joinStrings as $joinString ) {
			$containsEquals = strpos( $joinString, '=' );
			// Must be all-caps for now.
			$containsHolds = strpos( $joinString, ' HOLDS ' );
			$containsHoldsLike = strpos( $joinString, ' HOLDS LIKE ' );
			if ( $containsEquals ) {
				$joinParts = explode( '=', $joinString );
			} elseif ( $containsHoldsLike ) {
				$joinParts = explode( ' HOLDS LIKE ', $joinString );
			} elseif ( $containsHolds ) {
				$joinParts = explode( ' HOLDS ', $joinString );
			} else {
				throw new MWException( "Missing '=' in join condition ($joinString)." );
			}
			$joinPart1 = trim( $joinParts[0] );
			$tableAndField1 = explode( '.', $joinPart1 );
			if ( count( $tableAndField1 ) != 2 ) {
				throw new MWException( "Table and field name must both be specified in '$joinPart1'." );
			}
			list( $table1, $field1 ) = $tableAndField1;
			$joinPart2 = trim( $joinParts[1] );
			$tableAndField2 = explode( '.', $joinPart2 );
			if ( count( $tableAndField2 ) != 2 ) {
				throw new MWException( "Table and field name must both be specified in '$joinPart2'." );
			}
			list( $table2, $field2 ) = $tableAndField2;
			$joinCond = array(
				'joinType' => 'LEFT OUTER JOIN',
				'table1' => $table1,
				'field1' => $field1,
				'table2' => $table2,
				'field2' => $field2
			);
			if ( $containsHoldsLike ) {
				$joinCond['holds like'] = true;
			} elseif ( $containsHolds ) {
				$joinCond['holds'] = true;
			}
			$this->mCargoJoinConds[] = $joinCond;
		}

		// Now validate, to make sure that all the tables
		// are "joined" together. There's probably some more
		// efficient network algorithm for this sort of thing, but
		// oh well.
		$numUnmatchedTables = count( $this->mTableNames );
		$firstJoinCond = current( $this->mCargoJoinConds );
		$firstTableInJoins = $firstJoinCond['table1'];
		$matchedTables = array( $firstTableInJoins );
		do {
			$previousNumUnmatchedTables = $numUnmatchedTables;
			foreach ( $this->mCargoJoinConds as $joinCond ) {
				$table1 = $joinCond['table1'];
				$table2 = $joinCond['table2'];
				if ( !in_array( $table1, $this->mTableNames ) ) {
					throw new MWException( "Error: table \"$table1\" is not in list of table names." );
				}
				if ( !in_array( $table2, $this->mTableNames ) ) {
					throw new MWException( "Error: table \"$table2\" is not in list of table names." );
				}

				if ( in_array( $table1, $matchedTables ) && !in_array( $table2, $matchedTables ) ) {
					$matchedTables[] = $table2;
					$numUnmatchedTables--;
				}
				if ( in_array( $table2, $matchedTables ) && !in_array( $table1, $matchedTables ) ) {
					$matchedTables[] = $table1;
					$numUnmatchedTables--;
				}
			}
		} while ( $numUnmatchedTables > 0 && $numUnmatchedTables > $previousNumUnmatchedTables );

		if ( $numUnmatchedTables > 0 ) {
			foreach ( $this->mTableNames as $tableName ) {
				if ( !in_array( $tableName, $matchedTables ) ) {
					throw new MWException( "Error: Table \"$tableName\" is not included within the "
					. "join conditions." );
				}
			}
		}
	}

	/**
	 * Turn the very structured format that Cargo uses for join
	 * conditions into the one that MediaWiki uses - this includes
	 * adding the database prefix to each table name.
	 */
	function setMWJoinConds() {
		if ( $this->mCargoJoinConds == null ) {
			return;
		}

		$this->mJoinConds = array();
		foreach ( $this->mCargoJoinConds as $cargoJoinCond ) {
			$table2 = $cargoJoinCond['table2'];
			$this->mJoinConds[$table2] = array(
				$cargoJoinCond['joinType'],
				$this->mCargoDB->tableName( $cargoJoinCond['table1'] ) .
				'.' . $cargoJoinCond['field1'] . '=' .
				$this->mCargoDB->tableName( $cargoJoinCond['table2'] ) .
				'.' . $cargoJoinCond['field2']
			);
		}
	}

	function setOrderBy( $orderByStr = null ) {
		if ( $orderByStr != '' ) {
			$this->mOrderByStr = $orderByStr;
		} else {
			// By default, sort on the first field.
			reset( $this->mAliasedFieldNames );
			$firstField = current( $this->mAliasedFieldNames );
			if ( strpos( $firstField, '(' ) === false && strpos( $firstField, '.' ) === false ) {
				$this->mOrderByStr = $this->mCargoDB->addIdentifierQuotes( $firstField );
			} else {
				$this->mOrderByStr = $firstField;
			}
		}
	}

	static function getAndValidateSQLFunctions( $str ) {
		global $wgCargoAllowedSQLFunctions;

		$sqlFunctionMatches = array();
		$sqlFunctionRegex = '/(\b|\W)(\w*?)\s*\(/';
		preg_match_all( $sqlFunctionRegex, $str, $sqlFunctionMatches );
		$sqlFunctions = array_map( 'strtoupper', $sqlFunctionMatches[2] );
		$sqlFunctions = array_map( 'trim', $sqlFunctions );
		// Throw an error if any of these functions
		// are not in our "whitelist" of SQL functions.
		// Also add to this whitelist the SQL keywords AND, OR and
		// NOT, because the parsing can mistake these for functions.
		$logicalOperators = array( 'AND', 'OR', 'NOT' );
		$allowedFunctions = array_merge( $wgCargoAllowedSQLFunctions, $logicalOperators );
		foreach ( $sqlFunctions as $sqlFunction ) {
			// @TODO - fix the original regexp to avoid blank
			// strings, so that this check is not necessary.
			if ( $sqlFunction == '' ) {
				continue;
			}
			if ( !in_array( $sqlFunction, $allowedFunctions ) ) {
				throw new MWException( "Error: the SQL function \"$sqlFunction()\" is not allowed." );
			}
		}

		return $sqlFunctions;
	}

	/**
	 * Attempts to get the "field description" (type, etc.) of each field
	 * specified in a SELECT call (via a #cargo_query call), using the set
	 * of schemas for all data tables.
	 *
	 * Also does some validation of table names, field names, and any SQL
	 * functions contained in this clause.
	 */
	function setDescriptionsForFields() {
		$this->mFieldDescriptions = array();
		$this->mFieldTables = array();
		foreach ( $this->mAliasedFieldNames as $alias => $origFieldName ) {
			$tableName = null;
			$fieldName = null;
			$description = new CargoFieldDescription();

			$fieldPattern = '/^([-\w$]+)([.]([-\w$]+))?$/';
			$fieldPatternFound = preg_match( $fieldPattern, $origFieldName, $fieldPatternMatches );
			$stringPatternFound = false;
			$hasFunctionCall = false;

			if ( $fieldPatternFound ) {
				switch ( count( $fieldPatternMatches ) ) {
					case 2:
						$fieldName = $fieldPatternMatches[1];
						break;
					case 4:
						$tableName = $fieldPatternMatches[1];
						$fieldName = $fieldPatternMatches[3];
						break;
				}
			} else {
				$stringPattern = '/^(([\'"]).*?\2)(.+)?$/';
				$stringPatternFound = preg_match( $stringPattern, $origFieldName, $stringPatternMatches );
				if ( $stringPatternFound ) {
					// If the count is 3 we have a single quoted string
					// If the count is 4 we have stuff after it
					$stringPatternFound = count( $stringPatternMatches ) == 3;
				}

				if ( ! $stringPatternFound ) {
					$noQuotesOrigFieldName = CargoUtils::removeQuotedStrings( $origFieldName );

					$functionCallPattern = '/\w\s*\(/';
					$hasFunctionCall = preg_match( $functionCallPattern, $noQuotesOrigFieldName );
				}
			}

			// If it's a pre-defined field, we probably know its
			// type.
			if ( $fieldName == '_ID' || $fieldName == '_rowID' || $fieldName == '_pageID' ) {
				$description->mType = 'Integer';
			} elseif ( $fieldName == '_pageTitle' ) {
				// It's a string - do nothing.
			} elseif ( $fieldName == '_pageName' ) {
				$description->mType = 'Page';
			} elseif ( $stringPatternFound ) {
				// It's a quoted, literal string - do nothing.
			} elseif ( $hasFunctionCall ) {
				$sqlFunctions = self::getAndValidateSQLFunctions( $noQuotesOrigFieldName );
				$firstFunction = $sqlFunctions[0];
				if ( in_array( $firstFunction, array( 'COUNT', 'FLOOR', 'CEIL', 'ROUND' ) ) ) {
					$description->mType = 'Integer';
				} elseif ( in_array( $firstFunction, array( 'MAX', 'MIN', 'AVG', 'SUM', 'POWER', 'LN', 'LOG' ) ) ) {
					$description->mType = 'Float';
				} elseif ( in_array( $firstFunction,
						array( 'DATE', 'DATE_ADD', 'DATE_SUB', 'DATE_DIFF' ) ) ) {
					$description->mType = 'Date';
				}
				// If it's anything else ('CONCAT', 'SUBSTRING',
				// etc. etc.), we don't have to do anything.
			} else {
				// It's a standard field - though if it's
				// '_value', or ends in '__full', it's actually
				// the type of its corresponding field.
				if ( $fieldName == '_value' ) {
					if ( $tableName != null ) {
						list( $tableName, $fieldName ) = explode( '__', $tableName, 2 );
					} else {
						// We'll assume that there's
						// exactly one "field table" in
						// the list of tables -
						// otherwise a standalone call
						// to "_value" will presumably
						// crash the SQL call.
						foreach ( $this->mTableNames as $curTable ) {
							if ( strpos( $curTable, '__' ) !== false ) {
								list( $tableName, $fieldName ) = explode( '__', $curTable );
								break;
							}
						}
					}
				} elseif ( strlen( $fieldName ) > 6 &&
					strpos( $fieldName, '__full', strlen( $fieldName ) - 6 ) !== false ) {
					$fieldName = substr( $fieldName, 0, strlen( $fieldName ) - 6 );
				}
				if ( $tableName != null ) {
					if ( !array_key_exists( $tableName, $this->mTableSchemas ) ) {
						throw new MWException( "Error: no database table exists named \"$tableName\"." );
					} elseif ( !array_key_exists( $fieldName, $this->mTableSchemas[$tableName]->mFieldDescriptions ) ) {
						throw new MWException( "Error: no field named \"$fieldName\" found for the database table \"$tableName\"." );
					} else {
						$description = $this->mTableSchemas[$tableName]->mFieldDescriptions[$fieldName];
					}
				} elseif ( substr( $fieldName, -5 ) == '__lat' || substr( $fieldName, -5 ) == '__lon' ) {
					// Special handling for lat/lon
					// helper fields.
					$description->mType = 'Coordinates part';
					$tableName = '';
				} elseif ( substr( $fieldName, -11 ) == '__precision' ) {
					// Special handling for lat/lon
					// helper fields.
					// @TODO - we need validation on
					// __lat, __lon and __precision fields,
					// to make sure that they exist.
					$description->mType = 'Date precision';
					$tableName = '';
				} else {
					// Go through all the fields, until we
					// find the one matching this one.
					foreach ( $this->mTableSchemas as $curTableName => $tableSchema ) {
						if ( array_key_exists( $fieldName, $tableSchema->mFieldDescriptions ) ) {
							$description = $tableSchema->mFieldDescriptions[$fieldName];
							$tableName = $curTableName;
							break;
						}
					}

					// If we couldn't find a table name,
					// throw an error.
					if ( $tableName == '' ) {
						throw new MWException( "Error: no field named \"$fieldName\" found for any of the specified database tables." );
					}
				}
			}
			// Fix alias.
			$alias = trim( $alias );
			$this->mFieldDescriptions[$alias] = $description;
			$this->mFieldTables[$alias] = $tableName;
		}
	}

	function addToCargoJoinConds( $newCargoJoinConds ) {
		foreach ( $newCargoJoinConds as $newCargoJoinCond ) {
			// Go through to make sure it's not there already.
			$foundMatch = false;
			foreach ( $this->mCargoJoinConds as $cargoJoinCond ) {
				if ( $cargoJoinCond['table1'] == $newCargoJoinCond['table1'] &&
					$cargoJoinCond['field1'] == $newCargoJoinCond['field1'] &&
					$cargoJoinCond['table2'] == $newCargoJoinCond['table2'] &&
					$cargoJoinCond['field2'] == $newCargoJoinCond['field2'] ) {
					$foundMatch = true;
					continue;
				}
			}
			if ( !$foundMatch ) {
				$this->mCargoJoinConds[] = $newCargoJoinCond;
			}
		}
	}

	function addFieldTableToTableNames( $fieldTableName, $tableName ) {
		// Add it in in the correct place, if it should be added
		// at all.
		if ( in_array( $fieldTableName, $this->mTableNames ) ) {
			return;
		}
		if ( !in_array( $tableName, $this->mTableNames ) ) {
			// Show an error message here?
			return;
		}
		$indexOfMainTable = array_search( $tableName, $this->mTableNames );
		array_splice( $this->mTableNames, $indexOfMainTable + 1, 0, $fieldTableName );
	}

	/**
	 * Helper function for handleVirtualFields() - for the query's
	 * "fields" and "order by" values, the right replacement for "virtual
	 * fields" depends on whether the separate table for that field has
	 * been included in the query.
	 */
	function fieldTableIsIncluded( $fieldTableName ) {
		foreach ( $this->mCargoJoinConds as $cargoJoinCond ) {
			if ( $cargoJoinCond['table1'] == $fieldTableName ||
				$cargoJoinCond['table2'] == $fieldTableName ) {
				return true;
			}
		}
		return false;
	}

	/*
	 * Provides HOLDS functionality to WHERE clause by replacing $pattern
	 * in $subject with $replacement and setting $found to true if
	 * successful (leaves it untouched otehrwise). Includes modifying
	 * the regex beginning from a non-valid identifier character to word
	 * boundary.
	 */
	function substVirtualFieldName( &$subject, $pattern, $replacement, &$found ) {
		if ( preg_match( $pattern, $subject ) ) {
			$pattern = str_replace( '([^\w$,]|^)', '\b', $pattern);
			$pattern = str_replace( '([^\w$.,]|^)', '\b', $pattern);
			$subject = preg_replace( $pattern, $replacement, $subject );
			$found = true;
		}
	}

	function handleVirtualFields() {
		// The array-field alias can be found in a number of different
		// clauses. Handling depends on which clause it is:
		// "where" - make sure that "HOLDS" or "HOlDS LIKE" is
		//     specified. If it is, "translate" it, and add the values
		//     table to "tables" and "join on".
		// "join on" - make sure that "HOLDS" is specified, If it is,
		//     "translate" it, and add the values table to "tables".
		// "group by" - always "translate" it into the single value.
		// "having" - same as "group by".
		// "fields" - "translate" it, where the translation (i.e.
		//     the true field) depends on whether or not the values
		//     table is included.
		// "order by" - same as "fields".

		// First, create an array of the virtual fields in the current
		// set of tables.
		$virtualFields = array();
		foreach ( $this->mTableSchemas as $tableName => $tableSchema ) {
			foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
				if ( $fieldDescription->mIsList ) {
					$virtualFields[] = array(
						'fieldName' => $fieldName,
						'tableName' => $tableName
					);
				}
			}
		}

		// "where"
		$matches = array();
		foreach ( $virtualFields as $virtualField ) {
			$fieldName = $virtualField['fieldName'];
			$tableName = $virtualField['tableName'];
			$fieldTableName = $tableName . '__' . $fieldName;
			$replacementFieldName = $fieldTableName . '._value';
			$patternSuffix = '\b\s*/i';
			$fieldReplaced = false;
			$throwException = false;

			$patternSimple = array(
				CargoUtils::getSQLTableAndFieldPattern( $tableName, $fieldName ),
				CargoUtils::getSQLFieldPattern( $fieldName )
				);
			$patternRoot = array(
				CargoUtils::getSQLTableAndFieldPattern( $tableName, $fieldName, false ) . '\s+',
				CargoUtils::getSQLFieldPattern( $fieldName, false ) . '\s+'
				);

			for ( $i = 0 ; $i < 2 ; $i++ ) {
				if ( preg_match( $patternSimple[$i], $this->mWhereStr ) ) {

					$this->substVirtualFieldName(
						$this->mWhereStr,
						$patternRoot[$i] . 'HOLDS\s+NOT\s+LIKE' . $patternSuffix,
						"$replacementFieldName NOT LIKE ",
						$fieldReplaced);

					$this->substVirtualFieldName(
						$this->mWhereStr,
						$patternRoot[$i] . 'HOLDS\s+LIKE' . $patternSuffix,
						"$replacementFieldName LIKE ",
						$fieldReplaced);

					$this->substVirtualFieldName(
						$this->mWhereStr,
						$patternRoot[$i] . 'HOLDS\s+NOT' . $patternSuffix,
						"$replacementFieldName!=",
						$fieldReplaced);

					$this->substVirtualFieldName(
						$this->mWhereStr,
						$patternRoot[$i] . 'HOLDS' . $patternSuffix,
						"$replacementFieldName=",
						$fieldReplaced);

					if ( preg_match( $patternSimple[$i], $this->mWhereStr ) ) {
						throw new MWException( "Error: operator for the virtual field '" .
							"$tableName.$fieldName' must be 'HOLDS', 'HOLDS NOT', '" .
							"HOLDS LIKE' or 'HOLDS NOT LIKE'." );
					}
				}
			}

			if ( $fieldReplaced ) {
				$this->addFieldTableToTableNames( $fieldTableName, $tableName );
				$this->mCargoJoinConds[] = array(
					'joinType' => 'LEFT OUTER JOIN',
					'table1' => $tableName,
					'field1' => $this->mCargoDB->addIdentifierQuotes( '_ID' ),
					'table2' => $fieldTableName,
					'field2' => $this->mCargoDB->addIdentifierQuotes( '_rowID' )
				);
			}
		}

		// "join on"
		$newCargoJoinConds = array();
		foreach ( $this->mCargoJoinConds as $i => $joinCond ) {
			// We only handle 'HOLDS' here - no joining on
			// 'HOLDS LIKE'.
			if ( !array_key_exists( 'holds', $joinCond ) ) {
				continue;
			}

			foreach ( $virtualFields as $virtualField ) {
				$fieldName = $virtualField['fieldName'];
				$tableName = $virtualField['tableName'];
				if ( $fieldName != $joinCond['field1'] || $tableName != $joinCond['table1'] ) {
					continue;
				}
				$fieldTableName = $tableName . '__' . $fieldName;
				$this->addFieldTableToTableNames( $fieldTableName, $tableName );
				$newJoinCond = array(
					'joinType' => 'LEFT OUTER JOIN',
					'table1' => $tableName,
					'field1' => $this->mCargoDB->addIdentifierQuotes( '_ID' ),
					'table2' => $fieldTableName,
					'field2' => $this->mCargoDB->addIdentifierQuotes( '_rowID' )
				);
				$newCargoJoinConds[] = $newJoinCond;
				$newJoinCond2 = array(
					'joinType' => 'LEFT OUTER JOIN',
					'table1' => $fieldTableName,
					'field1' => '_value',
					'table2' => $this->mCargoJoinConds[$i]['table2'],
					'field2' => $this->mCargoJoinConds[$i]['field2']
				);
				$newCargoJoinConds[] = $newJoinCond2;
				// Is it safe to unset an array value while
				// cycling through the array? Hopefully.
				unset( $this->mCargoJoinConds[$i] );
			}
		}
		$this->addToCargoJoinConds( $newCargoJoinConds );

		// "group by" and "having"
		// We handle these before "fields" and "order by" because,
		// unlike those two, a virtual field here can affect the
		// set of tables and fields being included - which will
		// affect the other two.
		$matches = array();
		foreach ( $virtualFields as $virtualField ) {
			$fieldName = $virtualField['fieldName'];
			$tableName = $virtualField['tableName'];
			$pattern1 = CargoUtils::getSQLTableAndFieldPattern( $tableName, $fieldName );
			$foundMatch1 = preg_match( $pattern1, $this->mGroupByStr, $matches );
			$pattern2 = CargoUtils::getSQLFieldPattern( $fieldName );
			$foundMatch2 = false;

			if ( !$foundMatch1 ) {
				$foundMatch2 = preg_match( $pattern2, $this->mGroupByStr, $matches );
			}
			if ( $foundMatch1 || $foundMatch2 ) {
				$fieldTableName = $tableName . '__' . $fieldName;
				if ( !$this->fieldTableIsIncluded( $fieldTableName ) ) {
					$this->addFieldTableToTableNames( $fieldTableName, $tableName );
					$this->mCargoJoinConds[] = array(
						'joinType' => 'LEFT OUTER JOIN',
						'table1' => $tableName,
						'field1' => $this->mCargoDB->addIdentifierQuotes( '_ID' ),
						'table2' => $fieldTableName,
						'field2' => $this->mCargoDB->addIdentifierQuotes( '_rowID' )
					);
				}
				$replacement = "$fieldTableName._value";

				if ( $foundMatch1 ) {
					$this->mGroupByStr = preg_replace( $pattern1, $replacement, $this->mGroupByStr );
					$this->mHavingStr = preg_replace( $pattern1, $replacement, $this->mHavingStr );
				} elseif ( $foundMatch2 ) {
					$this->mGroupByStr = preg_replace( $pattern2, $replacement, $this->mGroupByStr );
					$this->mHavingStr = preg_replace( $pattern2, $replacement, $this->mHavingStr );
				}
			}
		}

		// "fields"
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			$fieldDescription = $this->mFieldDescriptions[$alias];

			if ( strpos( $fieldName, '.' ) !== false ) {
				// This could probably be done better with
				// regexps.
				list( $tableName, $fieldName ) = explode( '.', $fieldName, 2 );
			} else {
				$tableName = $this->mFieldTables[$alias];
			}

			// We're only interested in virtual list fields.
			$isVirtualField = false;
			foreach ( $virtualFields as $virtualField ) {
				if ( $fieldName == $virtualField['fieldName'] && $tableName == $virtualField['tableName'] ) {
					$isVirtualField = true;
					break;
				}
			}
			if ( !$isVirtualField ) {
				continue;
			}

			// Since the field name is an alias, it should get
			// translated, to either the "full" equivalent or to
			// the "value" field in the field table - depending on
			// whether or not that field has been "joined" on.
			$fieldTableName = $tableName . '__' . $fieldName;
			if ( $this->fieldTableIsIncluded( $fieldTableName ) ) {
				$fieldName = $fieldTableName . '._value';
			} else {
				$fieldName .= '__full';
			}
			$this->mAliasedFieldNames[$alias] = $fieldName;
		}

		// "order by"
		$matches = array();
		foreach ( $virtualFields as $virtualField ) {
			$fieldName = $virtualField['fieldName'];
			$tableName = $virtualField['tableName'];
			$pattern1 = CargoUtils::getSQLTableAndFieldPattern( $tableName, $fieldName );
			$foundMatch1 = preg_match( $pattern1, $this->mOrderByStr, $matches );
			$pattern2 = CargoUtils::getSQLFieldPattern( $fieldName );
			$foundMatch2 = false;

			if ( !$foundMatch1 ) {
				$foundMatch2 = preg_match( $pattern2, $this->mOrderByStr, $matches );
			}
			if ( $foundMatch1 || $foundMatch2 ) {
				$fieldTableName = $tableName . '__' . $fieldName;
				if ( $this->fieldTableIsIncluded( $fieldTableName ) ) {
					$replacement = "$fieldTableName._value";
				} else {
					$replacement = $tableName . '.' . $fieldName . '__full';
				}
				if ( $foundMatch1 ) {
					$this->mOrderByStr = preg_replace( $pattern1, $replacement, $this->mOrderByStr );
				} elseif ( $foundMatch2 ) {
					$this->mOrderByStr = preg_replace( $pattern2, $replacement, $this->mOrderByStr );
				}
			}
		}
	}

	/**
	 * Similar to handleVirtualFields(), but handles coordinates fields
	 * instead of fields that hold lists. This handling is much simpler.
	 */
	function handleVirtualCoordinateFields() {
		// Coordinate fields can be found in the "fields" and "where"
		// clauses. The following handling is done:
		// "fields" - "translate" it, where the translation (i.e.
		//     the true field) depends on whether or not the values
		//     table is included.
		// "where" - make sure that "NEAR" is specified. If it is,
		//     translate the clause accordingly.

		// First, create an array of the coordinate fields in the
		// current set of tables.
		$coordinateFields = array();
		foreach ( $this->mTableSchemas as $tableName => $tableSchema ) {
			foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
				if ( $fieldDescription->mType == 'Coordinates' ) {
					$coordinateFields[] = array(
						'fieldName' => $fieldName,
						'tableName' => $tableName
					);
				}
			}
		}

		// "fields"
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			$fieldDescription = $this->mFieldDescriptions[$alias];

			if ( strpos( $fieldName, '.' ) !== false ) {
				// This could probably be done better with
				// regexps.
				list( $tableName, $fieldName ) = explode( '.', $fieldName, 2 );
			} else {
				$tableName = $this->mFieldTables[$alias];
			}

			// We have to do this roundabout checking, instead
			// of just looking at the type of each field alias,
			// because we want to find only the *virtual*
			// coordinate fields.
			$isCoordinateField = false;
			foreach ( $coordinateFields as $coordinateField ) {
				if ( $fieldName == $coordinateField['fieldName'] &&
					$tableName == $coordinateField['tableName'] ) {
					$isCoordinateField = true;
					break;
				}
			}
			if ( !$isCoordinateField ) {
				continue;
			}

			// Since the field name is an alias, it should get
			// translated to its "full" equivalent.
			$fullFieldName = $fieldName . '__full';
			$this->mAliasedFieldNames[$alias] = $fullFieldName;

			// Add in the 'lat' and 'lon' fields as well - we'll
			// need them, if a map is being displayed.
			$this->mAliasedFieldNames[$fieldName . '  lat'] = $fieldName . '__lat';
			$this->mAliasedFieldNames[$fieldName . '  lon'] = $fieldName . '__lon';
		}

		// "where"
		// @TODO - add handling for "HOLDS POINT NEAR"
		$matches = array();
		foreach ( $coordinateFields as $coordinateField ) {
			$fieldName = $coordinateField['fieldName'];
			$tableName = $coordinateField['tableName'];
			$patternSuffix = '(\s+NEAR\s*)\(([^)]*)\)/i';

			$pattern1 = CargoUtils::getSQLTableAndFieldPattern( $tableName, $fieldName, false ) . $patternSuffix;
			$foundMatch1 = preg_match( $pattern1, $this->mWhereStr, $matches );
			if ( !$foundMatch1 ) {
				$pattern2 = CargoUtils::getSQLFieldPattern( $fieldName, false ) . $patternSuffix;
				$foundMatch2 = preg_match( $pattern2, $this->mWhereStr, $matches );
			}
			if ( $foundMatch1 || $foundMatch2 ) {
				// If no "NEAR", throw an error.
				if ( count( $matches ) != 4 ) {
					throw new MWException( "Error: operator for the virtual coordinates field "
					. "'$tableName.$fieldName' must be 'NEAR'." );
				}
				$coordinatesAndDistance = explode( ',', $matches[3] );
				if ( count( $coordinatesAndDistance ) != 3 ) {
					throw new MWException( "Error: value for the 'NEAR' operator must be of the form "
					. "\"(latitude, longitude, distance)\"." );
				}
				list( $latitude, $longitude, $distance ) = $coordinatesAndDistance;
				$distanceComponents = explode( ' ', trim( $distance ) );
				if ( count( $distanceComponents ) != 2 ) {
					throw new MWException( "Error: the third argument for the 'NEAR' operator, "
					. "representing the distance, must be of the form \"number unit\"." );
				}
				list( $distanceNumber, $distanceUnit ) = $distanceComponents;
				$distanceNumber = trim( $distanceNumber );
				$distanceUnit = trim( $distanceUnit );
				list( $latDistance, $longDistance ) = self::distanceToDegrees( $distanceNumber, $distanceUnit,
						$latitude );
				// There are much better ways to do this, but
				// for now, just make a "bounding box" instead
				// of a bounding circle.
				$newWhere = " $tableName.{$fieldName}__lat >= " . max( $latitude - $latDistance, -90 ) .
					" AND $tableName.{$fieldName}__lat <= " . min( $latitude + $latDistance, 90 ) .
					" AND $tableName.{$fieldName}__lon >= " . max( $longitude - $longDistance, -180 ) .
					" AND $tableName.{$fieldName}__lon <= " . min( $longitude + $longDistance, 180 ) . ' ';

				if ( $foundMatch1 ) {
					$this->mWhereStr = preg_replace( $pattern1, $newWhere, $this->mWhereStr );
				} elseif ( $foundMatch2 ) {
					$this->mWhereStr = preg_replace( $pattern2, $newWhere, $this->mWhereStr );
				}
			}
		}
	}

	/**
	 * Returns the number of degrees of both latitude and longitude that
	 * correspond to the passed-in distance (in either kilometers or
	 * miles), based on the passed-in latitude. (Longitude doesn't matter
	 * when doing this conversion, but latitude does.)
	 */
	static function distanceToDegrees( $distanceNumber, $distanceUnit, $latString ) {
		if ( in_array( $distanceUnit, array( 'kilometers', 'kilometres', 'km' ) ) ) {
			$distanceInKM = $distanceNumber;
		} elseif ( in_array( $distanceUnit, array( 'miles', 'mi' ) ) ) {
			$distanceInKM = $distanceNumber * 1.60934;
		} else {
			throw new MWException( "Error: distance for 'NEAR' operator must be in either miles or "
			. "kilometers (\"$distanceUnit\" specified)." );
		}
		// The calculation of distance to degrees latitude is
		// essentially the same wherever you are on the globe, although
		// the longitude calculation is more complicated.
		$latDistance = $distanceInKM / 111;

		// Convert the latitude string to a latitude number - code is
		// copied from CargoUtils::parseCoordinatesString().
		$latIsNegative = false;
		if ( strpos( $latString, 'S' ) > 0 ) {
			$latIsNegative = true;
		}
		$latString = str_replace( array( 'N', 'S' ), '', $latString );
		if ( is_numeric( $latString ) ) {
			$latNum = floatval( $latString );
		} else {
			$latNum = CargoUtils::coordinatePartToNumber( $latString );
		}
		if ( $latIsNegative ) {
			$latNum *= -1;
		}

		$lengthOfOneDegreeLongitude = cos( deg2rad( $latNum ) ) * 111.321;
		$longDistance = $distanceInKM / $lengthOfOneDegreeLongitude;

		return array( $latDistance, $longDistance );
	}

	/**
	 * For each date field, also add its corresponding "precisicon"
	 * field (which indicates whether the date is year-only, etc.) to
	 * the query.
	 */
	function handleDateFields() {
		$dateFields = array();
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			if ( !array_key_exists( $alias, $this->mFieldDescriptions ) ) {
				continue;
			}
			$fieldDescription = $this->mFieldDescriptions[$alias];
			if ( ( $fieldDescription->mType == 'Date' || $fieldDescription->mType == 'Datetime' ) &&
				// Make sure this is an actual field and not a call
				// to a function, like DATE_FORMAT(), by checking for
				// the presence of '(' and ')' - there's probably a
				// more elegant way to do this.
				( strpos( $fieldName, '(' ) == false ) && ( strpos( $fieldName, ')' ) == false ) ) {
				$dateFields[$alias] = $fieldName;
			}
		}
		foreach ( $dateFields as $alias => $dateField ) {
			$precisionFieldName = $dateField . '__precision';
			$precisionFieldAlias = $alias . '__precision';
			$this->mAliasedFieldNames[$precisionFieldAlias] = $precisionFieldName;
		}
	}

	function handleSearchTextFields() {
		$searchTextFields = array();
		foreach ( $this->mTableSchemas as $tableName => $tableSchema ) {
			foreach ( $tableSchema->mFieldDescriptions as $fieldName => $fieldDescription ) {
				if ( $fieldDescription->mType == 'Searchtext' ) {
					$fieldAlias = array_search( $fieldName, $this->mAliasedFieldNames );
					if ( $fieldAlias === false ) {
						continue;
					}
					$searchTextFields[] = array(
						'fieldName' => $fieldName,
						'fieldAlias' => $fieldAlias,
						'tableName' => $tableName
					);
				}
			}
		}

		$matches = array();
		foreach ( $searchTextFields as $searchTextField ) {
			$fieldName = $searchTextField['fieldName'];
			$fieldAlias = $searchTextField['fieldAlias'];
			$tableName = $searchTextField['tableName'];
			$patternSuffix = '(\s+MATCHES\s*)([\'"][^\'"]*[\'"])/i';

			$pattern1 = CargoUtils::getSQLTableAndFieldPattern( $tableName, $fieldName, false ) . $patternSuffix;
			$foundMatch1 = preg_match( $pattern1, $this->mWhereStr, $matches );
			$pattern2 = CargoUtils::getSQLFieldPattern( $fieldName, false ) . $patternSuffix;
			$foundMatch2 = false;

			if ( !$foundMatch1 ) {
				$foundMatch2 = preg_match( $pattern2, $this->mWhereStr, $matches );
			}
			if ( $foundMatch1 || $foundMatch2 ) {
				$searchString = $matches[3];
				$newWhere = " MATCH($tableName.$fieldName) AGAINST ($searchString IN BOOLEAN MODE) ";

				if ( $foundMatch1 ) {
					$this->mWhereStr = preg_replace( $pattern1, $newWhere, $this->mWhereStr );
				} elseif ( $foundMatch2 ) {
					$this->mWhereStr = preg_replace( $pattern2, $newWhere, $this->mWhereStr );
				}
				$searchEngine = new CargoSearchMySQL();
				$searchTerms = $searchEngine->getSearchTerms( $searchString );
				// @TODO - does $tableName need to be in there?
				$this->mSearchTerms[$fieldAlias] = $searchTerms;
			}
		}
	}

	/**
	 * Adds the "cargo" table prefix for every element in the SQL query
	 * except for 'tables' and 'join on' - for 'tables', the prefix is
	 * prepended automatically by the MediaWiki query, while for
	 * 'join on' the prefixes are added when the object is created.
	 */
	function addTablePrefixesToAll() {
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			$this->mAliasedFieldNames[$alias] = $this->addTablePrefixes( $fieldName );
		}
		if ( !is_null( $this->mWhereStr ) ) {
			$this->mWhereStr = $this->addTablePrefixes( $this->mWhereStr );
		}
		$this->mGroupByStr = $this->addTablePrefixes( $this->mGroupByStr );
		$this->mHavingStr = $this->addTablePrefixes( $this->mHavingStr );
		$this->mOrderByStr = $this->addTablePrefixes( $this->mOrderByStr );
	}

	/**
	 * Calls a database SELECT query given the parts of the query; first
	 * appending the Cargo prefix onto table names where necessary.
	 */
	function run() {
		foreach ( $this->mTableNames as $tableName ) {
			if ( !$this->mCargoDB->tableExists( $tableName ) ) {
				throw new MWException( "Error: no database table exists named \"$tableName\"." );
			}
		}

		$selectOptions = array();

		if ( $this->mGroupByStr != '' ) {
			$selectOptions['GROUP BY'] = $this->mGroupByStr;
		}
		if ( $this->mHavingStr != '' ) {
			$selectOptions['HAVING'] = $this->mHavingStr;
		}
		// @TODO - need handling of non-ASCII characters in field
		// names, which for some reason cause problems in "ORDER BY"
		// specifically.
		$selectOptions['ORDER BY'] = $this->mOrderByStr;
		$selectOptions['LIMIT'] = $this->mQueryLimit;

		// Aliases need to be surrounded by quotes when we actually
		// call the DB query.
		$realAliasedFieldNames = array();
		foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
			// Starting in MW 1.27 (specifically, with
			// https://gerrit.wikimedia.org/r/#/c/286489/),
			// query aliases get escaped with quotes automatically.
			// Double-escaping leads to a query error.
			if ( version_compare( $GLOBALS['wgVersion'], '1.27', '<' ) ) {
				$alias = $this->mCargoDB->addIdentifierQuotes( $alias );
			}

			// If it's really a field name, add quotes around it.
			// (The quotes are mostly needed for Postgres, which
			// lowercases all unquoted fields.)
			if ( strpos( $fieldName, '(' ) === false && strpos( $fieldName, '.' ) === false && !$this->mCargoDB->isQuotedIdentifier( $fieldName ) ) {
				$fieldName = $this->mCargoDB->addIdentifierQuotes( $fieldName );
			}
			$realAliasedFieldNames[$alias] = $fieldName;
		}

		$res = $this->mCargoDB->select( $this->mTableNames, $realAliasedFieldNames, $this->mWhereStr, __METHOD__,
			$selectOptions, $this->mJoinConds );

		// Is there a more straightforward way of turning query
		// results into an array?
		$resultArray = array();
		while ( $row = $this->mCargoDB->fetchRow( $res ) ) {
			$resultsRow = array();
			foreach ( $this->mAliasedFieldNames as $alias => $fieldName ) {
				// Escape any HTML, to avoid JavaScript
				// injections and the like.
				$resultsRow[$alias] = htmlspecialchars( $row[$alias] );
			}
			$resultArray[] = $resultsRow;
		}

		return $resultArray;
	}

	function addTablePrefixes( $string ) {
		// Create arrays for doing replacements of table names within
		// the SQL by their "real" equivalents.
		$tableNamePatterns = array();
		foreach ( $this->mTableNames as $tableName ) {
			$tableNamePatterns[] = CargoUtils::getSQLTablePattern( $tableName );
		}

		return preg_replace_callback( $tableNamePatterns,
			array( $this, 'addQuotes' ), $string );
	}

	private function addQuotes( $matches ) {
		$beforeText = $matches[1];
		$tableName = $matches[2];
		$fieldName = $matches[3];
		return $beforeText .
			$this->mCargoDB->tableName( $tableName ) . "." .
			$this->mCargoDB->addIdentifierQuotes( $fieldName );
	}

}
