<?php
/** Custom-field fixtures shared only by extractor unit tests. @package MuriLinkTracker\Tests */

class MLTRTestPostMeta {
	public static array $values = array();
	public static array $reads = array();
	public static array $protected_keys = array();
}

function get_post_custom_keys( int $post_id ): ?array {
	return isset( MLTRTestPostMeta::$values[ $post_id ] ) ? array_keys( MLTRTestPostMeta::$values[ $post_id ] ) : null;
}
function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
	MLTRTestPostMeta::$reads[] = array( $post_id, $key, $single );
	return MLTRTestPostMeta::$values[ $post_id ][ $key ] ?? '';
}
function is_protected_meta( string $key, string $type = '' ): bool {
	return str_starts_with( $key, '_' ) || in_array( $key, MLTRTestPostMeta::$protected_keys, true );
}
