<?php
/** Another worker currently owns the short mutation lock. */
declare( strict_types=1 );
namespace MuriLinkTracker\Queue;
defined( 'ABSPATH' ) || exit;
class QueueBusyException extends \RuntimeException {}
