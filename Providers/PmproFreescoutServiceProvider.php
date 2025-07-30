<?php

namespace Modules\PmproFreescout\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;

//Module Alias
define( 'PMPRO_MODULE', 'pmprofreescout' );

class PmproFreescoutServiceProvider extends ServiceProvider
{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        // Add module's JS file to the application layout.
        \Eventy::addFilter('javascripts', function($javascripts) {
            $javascripts[] = \Module::getPublicPath(PMPRO_MODULE).'/js/laroute.js';
            $javascripts[] = \Module::getPublicPath(PMPRO_MODULE).'/js/module.js';
                return $javascripts;
        });

        // Add module's CSS file to the application layout.
        \Eventy::addFilter('stylesheets', function($styles) {
            $styles[] = \Module::getPublicPath(PMPRO_MODULE).'/css/module.css';
                return $styles;
        });

        //Add Mailbox Menu Items
        \Eventy::addAction('mailboxes.settings.menu', function($mailbox) {
            if (auth()->user()->isAdmin()) {
                echo \View::make('pmprofreescout::partials/settings_menu', ['mailbox' => $mailbox])->render();
            }
        }, 34);

        // Section parameters.
        \Eventy::addFilter('settings.section_params', function($params, $section) {

            if ($section != PMPRO_MODULE) {
                return $params;
            }

            $params['settings'] = [
                'pmpro.url' => [
                    'env' => 'PMPRO_URL',
                ],
                'pmpro.username' => [
                    'env' => 'PMPRO_USERNAME',
                ],
                'pmpro.password' => [
                    'env' => 'PMPRO_PASSWORD',
                ],
            ];

            return $params;
        }, 20, 2);

         // Section settings.
         \Eventy::addFilter('settings.section_settings', function($settings, $section) {

            if ($section != PMPRO_MODULE) {
                return $settings;
            }

            $settings['pmpro.url'] = config('pmpro.url');
            $settings['pmpro.username'] = config('pmpro.username');
            $settings['pmpro.password'] = config('pmpro.password');

            return $settings;
        }, 20, 2);

        \Eventy::addAction('conversation.after_prev_convs', function($customer, $conversation, $mailbox) {

            $results = [
                "data" => [],
                "error" => []
            ];

            $customer_email = $customer->getMainEmail();

            if (!$customer_email) {
                return;
            }

            // Make sure that we have settings for authentication.
            if (!\PmproFreescout::isMailboxApiEnabled($mailbox)) {
                return;
            }

            $settings = \PmproFreescout::getMailboxSettings($mailbox);

            // Get the data, this handles the caching for us. Let's not force it to get uncached data that's what refresh is for.
            $results = self::apiGetMemberInfo( $customer_email, $mailbox );

            echo \View::make('pmprofreescout::partials/orders', [
                'results'        => $results['data'],
                'error'          => $results['error'],
                'customer_email' => $customer_email,
                'load'           => false,
                'url'            => \PmproFreescout::getSanitizedUrl( $settings['url'] ),
            ])->render();
        }, 12, 3 );

		// Add a custom badge to the conversations.
		\Eventy::addAction('conversations_table.before_subject', function ($conversation) {
			$mailbox = $conversation->mailbox;

			// Make sure that we have settings for authentication.
			if (!\PmproFreescout::isMailboxApiEnabled($mailbox)) {
				return;
			}

			$customer_email = $conversation->customer->getMainEmail();
			$results = self::apiGetMemberInfo( $customer_email, $mailbox );

			if ( ! empty( $results['error'] ) ) {
				// If there is an error, we don't want to show the badge.
				return;
			}

			// Show the level name in a badge.
			$level_name = isset( $results['data']->level ) ? $results['data']->level : false;
			
			// No level found just bail.
			if ( ! $level_name ) {
				// If there is no level name, we don't want to show the badge.
				return;
			}

			// Figure out dynamically which CSS class to use based on the level name for the badge.
			$premium_levels = array( 'Plus', 'Builder', 'PMPro VIP' );
			if ( in_array( $level_name, $premium_levels ) ) {
				$css_class = 'badge badge-plus';
			} elseif ( $level_name === 'Standard' ) {
				$css_class = 'badge badge-standard';
			} else {
				$css_class = 'badge badge-default';
			}
			
			echo '<span class="' . $css_class . '">' . $level_name . '</span> ';
		});

		// Reorder the mailbox folders, maintain the default folders structure and squeeze in custom folders above the default. Default folders are 'type' 1-80, custom folders are 81+.
		\Eventy::addFilter('mailbox.folders', function ($folders, $mailbox) {
            return $folders
            ->filter(function($folder) { return $folder->type > 80; })  // Pull high-priority types first
            ->concat($folders->filter(function($folder) { return $folder->type <= 80; })) // Append the rest as-is
            ->values();
        }, 90, 2);
	}

    /**
     * Get Mailbox settings we need for authenticating.
     */
    public static function getMailboxSettings($mailbox) {
        return [
            'url' => $mailbox->meta['pmpro']['url'] ?? '',
            'username' => $mailbox->meta['pmpro']['username'] ?? '',
            'password' => $mailbox->meta['pmpro']['password'] ?? '',
        ];
    }

    /**
     * Get customer information for the customer based off their email address
     */
    public static function apiGetMemberInfo($customer_email, $mailbox = null, $force_refresh = false) {
        $response = [
            'error' => '',
            'data' => [],
        ];

		// Get settings from database or from config.
		if ( $mailbox && self::isMailboxApiEnabled( $mailbox ) ) {
			$settings = self::getMailboxSettings( $mailbox );

			$url = self::getSanitizedUrl( $settings['url'] );
			$username = $settings['username'];
			$password = $settings['password'];

			$cache_key = 'pmpro_orders_' . $mailbox->id . '_' . $customer_email;
		} else {
			$url = self::getSanitizedUrl( config('pmpro.url') );
			$username = config('pmpro.username');
			$password = config('pmpro.password');

			$cache_key = 'pmpro_orders_' . $customer_email;
		}

		// Check to see if the request is cached already.
		$cached_member_info = \Cache::get( $cache_key );

		if ( $cached_member_info && ! $force_refresh ) {
			$response['data'] = $cached_member_info;

			return $response;
		}

        $request_url = $url . 'wp-json/bbpress-support/v1/get-customer-info/';

        // Get data via REST API and return it.
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $request_url. '?user_email=' . $customer_email );
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $results = curl_exec($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            // If the request was okay, get data otherwise let's get an error yo!
            if ( $status_code == 200 ) {
                $response['data'] = json_decode( $results );

				// Cache request data for 60 minutes.
                \Cache::put( $cache_key, $response['data'], now()->addMinutes( 60 ) );
            } else {
                $response['error'] = self::errorCodeDescr( $status_code );
            }
        } catch ( Exception $e ) {
            $response['error'] = $e->getMessage();
        }

        return $response;
    }

    /**
     * Check if credentials are saved and working.
     * @return boolean Returns true if settings are stored.
     */
    public static function isMailboxApiEnabled($mailbox) {

        if (empty($mailbox) || empty($mailbox->meta['pmpro'])) {
            return false;
        }

        $settings = self::getMailboxSettings($mailbox);

        return (!empty($settings['url']) && !empty($settings['username']) && !empty($settings['password']));
    }

    /**
     * Sanitize the URL submitted to ensure it's always correct format.
     * @return string sanitized URL with trailing /.
     */
    public static function getSanitizedUrl($url = '') {
        if (empty($url)) {
            $url = config('pmpro.url');
        }

       $url = preg_replace("/https?:\/\//i", '', $url);

        if (substr($url, -1) != '/') {
            $url .= '/';
        }

        return 'https://'.$url;
    }

    /**
     * Function to decode REST API response codes and output an error for us.
     *
     * @return string Returns human readable error message if status isn't 200 for the API Request.
     */
    public static function errorCodeDescr($code) {

        switch ($code) {
            case 400:
                $descr = __('Bad request');
                break;
            case 401:
            case 403:
                $descr = __('Authentication or permission error, e.g. incorrect API keys or your store is protected with Basic HTTP Authentication');
                break;
            case 0:
            case 404:
                $descr = __('Store not found at the specified URL');
                break;
            case 500:
                $descr = __('Internal store error');
                break;
            default:
                $descr = __('Unknown error');
                break;
        }

        return $descr;
    }


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('pmprofreescout.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'pmpro'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/pmprofreescout');

        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ],'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/pmprofreescout';
        }, \Config::get('view.paths')), [$sourcePath]), 'pmprofreescout');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ .'/../Resources/lang');
    }

    /**
     * Register an additional directory of factories.
     * @source https://github.com/sebastiaanluca/laravel-resource-flow/blob/develop/src/Modules/ModuleServiceProvider.php#L66
     */
    public function registerFactories()
    {
        if (! app()->environment('production')) {
            app(Factory::class)->load(__DIR__ . '/../Database/factories');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
