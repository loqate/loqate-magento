<?php

/**
 * Lightweight stubs for the Magento framework (and Monolog) classes that the
 * module's unit tests reference, so the suite can run in CI without a full
 * Magento install.
 *
 * Each definition is guarded with class_exists()/interface_exists(..., false)
 * so that, when the real Magento classes are autoloadable (e.g. running inside
 * a Magento instance via dev/tests/unit), these stubs are skipped entirely and
 * the real implementations are used.
 *
 * These are deliberately minimal: they declare only the members the unit tests
 * mock or call. They are NOT a Magento shim and must never be shipped/loaded
 * outside the test bootstrap.
 */

namespace Monolog {
    if (!class_exists(\Monolog\Logger::class, false)) {
        class Logger
        {
            public function __construct($name = 'test', array $handlers = [], array $processors = [])
            {
            }

            public function emergency($message, array $context = []): void
            {
            }

            public function alert($message, array $context = []): void
            {
            }

            public function critical($message, array $context = []): void
            {
            }

            public function error($message, array $context = []): void
            {
            }

            public function warning($message, array $context = []): void
            {
            }

            public function notice($message, array $context = []): void
            {
            }

            public function info($message, array $context = []): void
            {
            }

            public function debug($message, array $context = []): void
            {
            }

            public function log($level, $message, array $context = []): void
            {
            }
        }
    }
}

namespace Magento\Framework\App\Helper {
    if (!class_exists(\Magento\Framework\App\Helper\Context::class, false)) {
        class Context
        {
        }
    }

    if (!class_exists(\Magento\Framework\App\Helper\AbstractHelper::class, false)) {
        class AbstractHelper
        {
            public function __construct(Context $context)
            {
            }
        }
    }
}

namespace Magento\Store\Model {
    if (!interface_exists(\Magento\Store\Model\StoreManagerInterface::class, false)) {
        interface StoreManagerInterface
        {
        }
    }
}

namespace Magento\Customer\Model {
    if (!class_exists(\Magento\Customer\Model\Session::class, false)) {
        class Session
        {
            public function getData($key = '', $clear = false)
            {
                return null;
            }

            public function setData($key, $value = null)
            {
                return $this;
            }
        }
    }
}

namespace Magento\Directory\Model {
    if (!class_exists(\Magento\Directory\Model\RegionFactory::class, false)) {
        class RegionFactory
        {
            public function create(array $data = [])
            {
                return null;
            }
        }
    }
}

namespace Magento\Framework\Module {
    if (!interface_exists(\Magento\Framework\Module\ModuleListInterface::class, false)) {
        interface ModuleListInterface
        {
            public function getOne($name);
        }
    }
}

namespace Magento\Framework\Data {
    if (!interface_exists(\Magento\Framework\Data\OptionSourceInterface::class, false)) {
        interface OptionSourceInterface
        {
            public function toOptionArray();
        }
    }
}

namespace Magento\Framework\Serialize {
    if (!interface_exists(\Magento\Framework\Serialize\SerializerInterface::class, false)) {
        interface SerializerInterface
        {
            public function serialize($data);

            public function unserialize($string);
        }
    }
}

namespace Magento\Framework\App\Action {
    if (!class_exists(\Magento\Framework\App\Action\Context::class, false)) {
        class Context
        {
            public function getMessageManager()
            {
                return null;
            }

            public function getResultRedirectFactory()
            {
                return null;
            }

            public function getRedirect()
            {
                return null;
            }
        }
    }
}

namespace Magento\Framework {
    if (!interface_exists(\Magento\Framework\UrlInterface::class, false)) {
        interface UrlInterface
        {
        }
    }
}

namespace Magento\Framework\Controller\Result {
    if (!class_exists(\Magento\Framework\Controller\Result\JsonFactory::class, false)) {
        class JsonFactory
        {
            public function create(array $data = [])
            {
                return null;
            }
        }
    }
}

namespace Magento\Directory\Model {
    if (!class_exists(\Magento\Directory\Model\CountryFactory::class, false)) {
        class CountryFactory
        {
            public function create(array $data = [])
            {
                return null;
            }
        }
    }
}

namespace Magento\Customer\Api\Data {
    if (!interface_exists(\Magento\Customer\Api\Data\AddressInterface::class, false)) {
        interface AddressInterface
        {
        }
    }
}

namespace Magento\Checkout\Block\Checkout {
    if (!interface_exists(\Magento\Checkout\Block\Checkout\LayoutProcessorInterface::class, false)) {
        interface LayoutProcessorInterface
        {
        }
    }
}

namespace {
    if (!function_exists('__')) {
        /**
         * Minimal stand-in for Magento's global translation function. Returns the
         * text with Magento-style %1/%2 placeholders substituted, as a plain
         * string (stringly compatible with the real Phrase via (string) casts).
         */
        function __($text, ...$arguments)
        {
            $text = (string)$text;
            if ($arguments && isset($arguments[0]) && is_array($arguments[0])) {
                $arguments = $arguments[0];
            }
            foreach (array_values($arguments) as $index => $value) {
                $text = str_replace('%' . ($index + 1), (string)$value, $text);
            }
            return $text;
        }
    }
}
