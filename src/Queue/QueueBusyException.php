<?php
/**
 * Another worker currently owns the short mutation lock.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );
namespace MuriLinkTracker\Queue;

defined( 'ABSPATH' ) || exit;
/** Signals contention for the short database mutation lock. */
class QueueBusyException extends \RuntimeException {}
