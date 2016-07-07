<?php

namespace Recca0120\LaravelPayum;

use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Payum\Core\Bridge\Symfony\ReplyToSymfonyResponseConverter;
use Payum\Core\Bridge\Symfony\Security\HttpRequestVerifier;
use Payum\Core\GatewayFactoryInterface;
use Payum\Core\Payum;
use Payum\Core\Registry\StorageRegistryInterface;
use Payum\Core\Storage\StorageInterface;
use Recca0120\LaravelPayum\Action\GetHttpRequestAction;
use Recca0120\LaravelPayum\Action\ObtainCreditCardAction;
use Recca0120\LaravelPayum\Action\RenderTemplateAction;
use Recca0120\LaravelPayum\Model\GatewayConfig;
use Recca0120\LaravelPayum\Security\TokenFactory;
use Recca0120\LaravelPayum\Storage\EloquentStorage;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'Recca0120\LaravelPayum\Http\Controllers';

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * boot.
     *
     * @method boot
     *
     * @param \Illuminate\Contracts\View\Factory      $viewFactory
     * @param \Illuminate\Routing\Router              $router
     */
    public function boot(ViewFactory $viewFactory, Router $router)
    {
        $viewFactory->addNamespace('payum', __DIR__.'/../resources/views');
        $this->handleRoutes($router);
        $this->handlePublishes();
    }

    /**
     * Register the service provider.
     *
     * @method register
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payum.php', 'payum');
        $this->app->singleton(PayumBuilder::class, function ($app) {
            $config = $app['config'];
            $payumBuilder = (new PayumBuilder($app))
                ->setTokenFactory(function (StorageInterface $tokenStorage, StorageRegistryInterface $registry) use ($app) {
                    return $app->make(TokenFactory::class, [$tokenStorage, $registry]);
                })
                ->setHttpRequestVerifier(function (StorageInterface $tokenStorage) {
                    return new HttpRequestVerifier($tokenStorage);
                })
                ->setCoreGatewayFactory(function (array $defaultConfig) use ($app) {
                    return $app->make(CoreGatewayFactory::class, [$app, $defaultConfig]);
                })
                ->setCoreGatewayFactoryConfig([
                    'payum.converter.reply_to_http_response' => ReplyToSymfonyResponseConverter::class,
                    'payum.action.get_http_request'          => GetHttpRequestAction::class,
                    'payum.action.obtain_credit_card'        => ObtainCreditCardAction::class,
                    'payum.action.render_template'           => RenderTemplateAction::class,
                    // ioc
                    // 'payum.action.get_http_request'   => 'payum.action.get_http_request',
                    // 'payum.action.obtain_credit_card' => 'payum.action.obtain_credit_card',
                ])
                ->setGenericTokenFactoryPaths([
                    'authorize' => 'payment.authorize',
                    'capture'   => 'payment.capture',
                    'notify'    => 'payment.notify',
                    'payout'    => 'payment.payout',
                    'refund'    => 'payment.refund',
                    'sync'      => 'payment.sync',
                    'done'      => 'payment.done',
                ]);

            $this->registerGatewayStorage($payumBuilder, $config)
                ->registerGatewayFactory($payumBuilder, $config)
                ->registerGatewayFactoryConfig($payumBuilder, $config)
                ->registerGatewayFactoryConfigStorage($payumBuilder, $config);

            return $payumBuilder;
        });

        $this->app->singleton(Payum::class, function ($app) {
            return $app->make(PayumBuilder::class)->getPayum();
        });

        $this->app->singleton(Payment::class, Payment::class);
        // ioc
        // $this->app->bind('payum.converter.reply_to_http_response', ReplyToSymfonyResponseConverter::class);
        // $this->app->bind('payum.action.get_http_request', GetHttpRequestAction::class);
        // $this->app->bind('payum.action.obtain_credit_card', ObtainCreditCardAction::class);
        // $this->app->bind('payum.action.render_template', RenderTemplateAction::class);
    }

    /**
     * registerGatewayStorage.
     *
     * @method registerGatewayStorage
     *
     * @param \Recca0120\LaravelPayum\PayumBuilder    $payumBuilder
     * @param \Illuminate\Contracts\Config\Repository $configRepository
     *
     * @return static
     */
    protected function registerGatewayStorage(PayumBuilder $payumBuilder, ConfigContract $config)
    {
        if ($config->get('payum.storage.token') === 'database') {
            $payumBuilder->addEloquentStorages();
        } else {
            $payumBuilder->addDefaultStorages();
        }

        return $this;
    }

    /**
     * registerGatewayFactory.
     *
     * @method registerGatewayFactory
     *
     * @param \Recca0120\LaravelPayum\PayumBuilder    $payumBuilder
     * @param \Illuminate\Contracts\Config\Repository $configRepository
     *
     * @return static
     */
    protected function registerGatewayFactory(PayumBuilder $payumBuilder, ConfigContract $config)
    {
        $gatewayFactories = $config->get('payum.gatewayFactories');
        foreach ($gatewayFactories as $factoryName => $factoryClass) {
            $payumBuilder->addGatewayFactory($factoryName, function (array $config, GatewayFactoryInterface $coreGatewayFactory) use ($factoryClass) {
                return $this->app->make($factoryClass, [$config, $coreGatewayFactory]);
            });
        }

        return $this;
    }

    /**
     * registerGatewayFactoryConfig.
     *
     * @method registerGatewayFactoryConfig
     *
     * @param \Recca0120\LaravelPayum\PayumBuilder    $payumBuilder
     * @param \Illuminate\Contracts\Config\Repository $configRepository
     *
     * @return static
     */
    protected function registerGatewayFactoryConfig(PayumBuilder $payumBuilder, ConfigContract $config)
    {
        $gatewayConfigs = $config->get('payum.gatewayConfigs');
        foreach ($gatewayConfigs as $factoryName => $options) {
            $gatewayName = array_get($options, 'gatewayName');
            $gatewayConfig = array_get($options, 'config', []);
            $payumBuilder->addGateway($gatewayName, array_merge([
                'factory' => $factoryName,
            ], $gatewayConfig));
        }

        return $this;
    }

    /**
     * registerGatewayFactoryConfigStorage.
     *
     * @method registerGatewayFactoryConfigStorage
     *
     * @param \Recca0120\LaravelPayum\PayumBuilder    $payumBuilder
     * @param \Illuminate\Contracts\Config\Repository $configRepository
     *
     * @return static
     */
    protected function registerGatewayFactoryConfigStorage(PayumBuilder $payumBuilder, ConfigContract $config)
    {
        if ($config->get('payum.storage.gatewayConfig') === 'database') {
            $payumBuilder->setGatewayConfigStorage($this->app->make(EloquentStorage::class, [
                'modelClass' => GatewayConfig::class,
            ]));
        }

        return $this;
    }

    /**
     * register routes.
     *
     * @param Illuminate\Routing\Router $router
     *
     * @return void
     */
    protected function handleRoutes(Router $router)
    {
        if ($this->app->routesAreCached() === false) {
            $prefix = 'payment';
            $router->group([
                'as'         => 'payment.',
                'middleware' => 'web',
                'namespace'  => $this->namespace,
                'prefix'     => $prefix,
            ], function (Router $router) {
                require __DIR__.'/Http/routes.php';
            });
        }
    }

    /**
     * handle publishes.
     *
     * @return void
     */
    protected function handlePublishes()
    {
        $this->publishes([
            __DIR__.'/../config/payum.php' => config_path('payum.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../resources/views' => base_path('resources/views/vendor/payum'),
        ], 'views');

        $this->publishes([
            __DIR__.'/../database/migrations' => base_path('database/migrations'),
        ], 'public');
    }
}