<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit0d22bfac805e4649f9fd07a176379b35
{
    public static $prefixLengthsPsr4 = array (
        'c' => 
        array (
            'ceLTIc\\LTI\\' => 11,
        ),
        'F' => 
        array (
            'Firebase\\JWT\\' => 13,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'ceLTIc\\LTI\\' => 
        array (
            0 => __DIR__ . '/..' . '/celtic/lti/src',
        ),
        'Firebase\\JWT\\' => 
        array (
            0 => __DIR__ . '/..' . '/firebase/php-jwt/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit0d22bfac805e4649f9fd07a176379b35::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit0d22bfac805e4649f9fd07a176379b35::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}