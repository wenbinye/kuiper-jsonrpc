<?php

declare(strict_types=1);

namespace kuiper\jsonrpc\config;

use function DI\autowire;
use function DI\factory;
use kuiper\annotations\AnnotationReaderInterface;
use kuiper\di\annotation\Bean;
use kuiper\di\ComponentCollection;
use kuiper\di\ContainerBuilderAwareTrait;
use kuiper\di\DefinitionConfiguration;
use kuiper\helper\Text;
use kuiper\jsonrpc\annotation\JsonRpcService;
use kuiper\jsonrpc\server\JsonRpcServerFactory;
use kuiper\rpc\annotation\Ignore;
use kuiper\rpc\server\Service;
use kuiper\swoole\Application;
use kuiper\swoole\ServerConfig;
use kuiper\swoole\ServerPort;
use kuiper\web\LineRequestLogFormatter;
use kuiper\web\RequestLogFormatterInterface;
use Psr\Container\ContainerInterface;

abstract class AbstractJsonRpcServerConfiguration implements DefinitionConfiguration
{
    use ContainerBuilderAwareTrait;

    public function getDefinitions(): array
    {
        return [
            JsonRpcServerFactory::class => factory([JsonRpcServerFactory::class, 'createFromContainer']),
            RequestLogFormatterInterface::class => autowire(LineRequestLogFormatter::class),
        ];
    }

    /**
     * @return Service[]
     */
    protected function getJsonrpcServices(ContainerInterface $container, ServerConfig $serverConfig, string $serverType, int $weight): array
    {
        $serverPort = null;
        foreach ($serverConfig->getPorts() as $port) {
            if ($port->getServerType() === $serverType) {
                $serverPort = $port;
                break;
            }
        }
        if (null === $serverPort) {
            throw new \InvalidArgumentException('Cannot find port use http protocol');
        }
        if ('0.0.0.0' === $serverPort->getHost()) {
            $serverPort = new ServerPort(gethostbyname(gethostname()), $serverPort->getPort(), $serverPort->getServerType());
        }
        $annotationReader = $container->get(AnnotationReaderInterface::class);
        $services = [];
        /** @var JsonRpcService $annotation */
        foreach (ComponentCollection::getAnnotations(JsonRpcService::class) as $annotation) {
            if (null !== $annotation->name) {
                $serviceName = $annotation->name;
            } else {
                $serviceName = $this->getServiceName($annotation->getTarget());
            }
            $services[$serviceName] = new Service(
                $serviceName,
                $annotation->version ?? '1.0',
                $container->get($annotation->getComponentId()),
                $this->getMethods($annotation->getTarget(), $annotationReader),
                $serverPort,
                $weight
            );
        }
        foreach ($container->get('application.jsonrpc.server.services') ?? [] as $serviceName => $service) {
            if (is_string($service)) {
                $service = ['class' => $service];
            }
            $class = new \ReflectionClass($service['class']);
            if (!is_string($serviceName)) {
                $serviceName = $service['name'] ?? $this->getServiceName($class);
            }
            $services[$serviceName] = new Service(
                $serviceName,
                $service['version'] ?? '1.0',
                $container->get($service),
                $this->getMethods($class, $annotationReader),
                $serverPort,
                $weight
            );
        }

        return $services;
    }

    private function getServiceName(\ReflectionClass $class): string
    {
        if ($class->isInterface()) {
            $serviceClass = $class->getName();
        } else {
            foreach ($class->getInterfaceNames() as $interfaceName) {
                $parts = explode('\\', $interfaceName);
                $shortName = end($parts);
                if (Text::startsWith($class->getShortName(), $shortName)
                    || Text::startsWith($shortName, $class->getShortName())) {
                    $serviceClass = $interfaceName;
                    break;
                }
            }
        }
        if (isset($serviceClass)) {
            return str_replace('\\', '.', $serviceClass);
        }
        throw new \InvalidArgumentException('Cannot resolve service name from '.$class->getName());
    }

    private function getMethods(\ReflectionClass $class, AnnotationReaderInterface $annotationReader): array
    {
        $methods = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (null !== $annotationReader->getMethodAnnotation($method, Ignore::class)) {
                continue;
            }
            $methods[] = $method->getName();
        }

        return $methods;
    }

    /**
     * @Bean("jsonrpcServerMiddlewares")
     */
    public function jsonrpcServerMiddlewares(ContainerInterface $container): array
    {
        $middlewares = [];
        foreach (Application::getInstance()->getConfig()->get('application.jsonrpc.server.middleware', []) as $middleware) {
            $middlewares[] = $container->get($middleware);
        }

        return $middlewares;
    }
}
