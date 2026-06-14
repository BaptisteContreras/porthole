<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->private()
        ->autowire()
        ->autoconfigure();

    $services->load('Porthole\\', '../src/')
        ->exclude([
            '../src/Event/',
            '../src/Background/',
            '../src/Page/',
            '../src/Kernel.php',
            '../src/Harbor/AuditLogBuilder.php',
            '../src/Harbor/AuditLogEntry.php',
            '../src/Harbor/HarborContext.php',
            '../src/Report/ImageReport.php',
            '../src/Report/ImageReportRow.php',
            '../src/Report/ImageReportView.php',
            '../src/Report/UserReport.php',
            '../src/Report/UserReportRow.php',
            '../src/Report/UserReportView.php',
            '../src/Result/InvalidReportFileException.php',
            '../src/Tui/Navigator.php',
            '../src/UseCase/GenerateReportCommand.php',
        ]);

    // HttpClient: vendor class, factory required
    $services->set(HttpClientInterface::class)
        ->factory([HttpClient::class, 'create']);

    // Serializer: vendor classes wired explicitly
    $services->set(AttributeLoader::class);
    $services->set(ClassMetadataFactory::class)
        ->arg('$loader', service(AttributeLoader::class));
    $services->set(MetadataAwareNameConverter::class)
        ->arg('$metadataFactory', service(ClassMetadataFactory::class));
    $services->set(ReflectionExtractor::class);
    $services->set(PropertyInfoExtractor::class)
        ->arg('$typeExtractors', [service(ReflectionExtractor::class)]);
    $services->set(ObjectNormalizer::class)
        ->arg('$classMetadataFactory', service(ClassMetadataFactory::class))
        ->arg('$nameConverter', service(MetadataAwareNameConverter::class))
        ->arg('$propertyTypeExtractor', service(PropertyInfoExtractor::class));
    $services->set(CsvEncoder::class);
    $services->set(Serializer::class)
        ->arg('$normalizers', [service(ObjectNormalizer::class)])
        ->arg('$encoders', [service(CsvEncoder::class)]);

    $container->services()->alias(SerializerInterface::class, Serializer::class);
    $container->services()->alias(DecoderInterface::class, Serializer::class);
    $container->services()->alias(DenormalizerInterface::class, Serializer::class);
};
