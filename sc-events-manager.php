<?php

/**
 * Plugin Name:       SC Events Manager
 * Plugin URI:        https://screencandy.co.uk
 * Description:       A site-agnostic events calendar. On first activation, guides you through naming your own event post type (e.g. "Show", "Gig", "Class") before anything is registered.
 * Version:           1.6.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Neil Sayers
 * Author URI:        https://screencandy.co.uk
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sc-events-manager
 */

namespace SCEventsManager;

if (! defined('ABSPATH')) {
    exit;
}

define('SCEM_VERSION', '1.6.0');
define('SCEM_FILE', __FILE__);
define('SCEM_PATH', \plugin_dir_path(__FILE__));
define('SCEM_URL', \plugin_dir_url(__FILE__));

/**
 * Minimal PSR-4-style autoloader so this plugin has zero build step
 * or Composer dependency — it just needs to be copied into any site's
 * wp-content/plugins and activated.
 */
\spl_autoload_register(function (string $class): void {
    $prefix = __NAMESPACE__.'\\';

    if (! \str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \substr($class, \strlen($prefix));
    $path = SCEM_PATH.'src/'.\str_replace('\\', '/', $relative).'.php';

    if (\is_file($path)) {
        require $path;
    }
});

\register_activation_hook(__FILE__, [Setup\Activator::class, 'activate']);
\register_deactivation_hook(__FILE__, [Setup\Activator::class, 'deactivate']);

require SCEM_PATH.'src/Frontend/template-functions.php';

Plugin::instance()->boot();
