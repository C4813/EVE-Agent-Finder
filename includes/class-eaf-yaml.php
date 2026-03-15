<?php
/**
 * EAF_YAML – Streaming line-by-line parser for CCP SDE YAML files.
 *
 * Handles the standard SDE pattern without requiring the php-yaml PECL extension:
 *
 *   INTEGER_ID:
 *     field: scalar_value
 *     name:
 *       en: English Name
 *     nestedBlock:
 *       subField: value
 *     listField:
 *     - ignored_item
 *
 * Yields arrays: [ 'id' => int, 'fields' => [ 'key' => value, 'name.en' => string, … ] ]
 *
 * Scalar values are cast: integers, floats, true/false/null, quoted strings, bare strings.
 * List items and deeper-than-needed nesting are silently skipped.
 * Uses fgets() so 50 MB+ files never blow the memory limit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_YAML {

	/**
	 * Stream-parse a CCP SDE YAML file.
	 *
	 * @param  string $path Absolute path to the .yaml file (may be a PHP tmp file).
	 * @return \Generator   Each yield: [ 'id' => int, 'fields' => array ]
	 */
	public static function stream( string $path ): \Generator {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem does not support fgets() streaming, which is required to parse 50 MB+ SDE files without exhausting memory.
		$fh = @fopen( $path, 'r' );
		if ( ! $fh ) {
			return;
		}

		$top_id    = null;
		$fields    = [];
		// Stack entries: [ 'indent' => int, 'key' => string ]
		// Tracks the ancestor keys of the current line so we can build dotted paths.
		$key_stack = [];

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$raw      = rtrim( $line, "\r\n" );
			$stripped = trim( $raw );

			// Skip blank lines, comments, YAML document markers
			if ( $stripped === '' || $stripped[0] === '#' ||
			     $stripped === '---' || $stripped === '...' ) {
				continue;
			}

			$indent = strlen( $raw ) - strlen( ltrim( $raw ) );

			// ── Top-level integer key (indent 0) ─────────────────────────
			if ( $indent === 0 ) {
				// Emit the completed record before starting a new one
				if ( $top_id !== null ) {
					yield [ 'id' => $top_id, 'fields' => $fields ];
				}

				$colon  = strpos( $stripped, ':' );
				$id_str = ( $colon !== false ) ? substr( $stripped, 0, $colon ) : $stripped;
				$top_id    = (int) trim( $id_str );
				$fields    = [];
				$key_stack = [];
				continue;
			}

			if ( $top_id === null ) {
				continue; // haven't seen a top-level key yet
			}

			// Skip YAML list items (- value)
			if ( $stripped[0] === '-' ) {
				continue;
			}

			// ── Parse "key: value" or "key:" (block opener) ──────────────
			$colon = strpos( $stripped, ':' );
			if ( $colon === false ) {
				continue; // malformed line
			}

			$key     = substr( $stripped, 0, $colon );
			$val_raw = ltrim( substr( $stripped, $colon + 1 ) );

			// Pop stack entries at the same indent or deeper —
			// they are siblings or children of a sibling, not ancestors.
			while ( ! empty( $key_stack ) && end( $key_stack )['indent'] >= $indent ) {
				array_pop( $key_stack );
			}

			if ( $val_raw === '' ) {
				// No inline value: this key opens a nested block.
				// Push it so child lines can build a dotted path through it.
				$key_stack[] = [ 'indent' => $indent, 'key' => $key ];
			} else {
				// Scalar value: record under the full dotted ancestor path.
				$ancestors = array_column( $key_stack, 'key' );
				$full_key  = empty( $ancestors )
					? $key
					: implode( '.', $ancestors ) . '.' . $key;

				$fields[ $full_key ] = self::cast( $val_raw );
			}
		}

		// Emit the final record
		if ( $top_id !== null ) {
			yield [ 'id' => $top_id, 'fields' => $fields ];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );
	}

	/**
	 * Cast a raw YAML scalar string to the appropriate PHP type.
	 *
	 * @param  string $val  The raw string after the colon, already ltrimmed.
	 * @return mixed        int | float | bool | null | string
	 */
	public static function cast( string $val ): mixed {
		$len = strlen( $val );

		// Quoted string — strip surrounding quotes
		if ( $len >= 2 ) {
			$first = $val[0];
			$last  = $val[ $len - 1 ];
			if ( ( $first === '"' && $last === '"' ) ||
			     ( $first === "'" && $last === "'" ) ) {
				return substr( $val, 1, -1 );
			}
		}

		$lower = strtolower( $val );
		if ( $lower === 'true'  || $lower === 'yes' ) return true;
		if ( $lower === 'false' || $lower === 'no'  ) return false;
		if ( $lower === 'null'  || $lower === '~'   ) return null;

		// Integer (handles negative)
		if ( preg_match( '/^-?\d+$/', $val ) ) {
			return (int) $val;
		}
		// Float / scientific notation
		if ( is_numeric( $val ) ) {
			return (float) $val;
		}

		return $val;
	}
}
