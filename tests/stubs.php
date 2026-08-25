<?php
/**
 * The slice of the SnappyMail core that Invitations touches, stubbed.
 *
 * Only what the plugin actually calls is reproduced, with the same names and
 * signatures, so the tests exercise the plugin's own code rather than a
 * rewritten copy of it.
 *
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace RainLoop {
    class Settings
    {
        public function __construct(private array $conf = []) {}
        public function GetConf(string $key, $default = null) { return $this->conf[$key] ?? $default; }
    }

    class Notifications
    {
        const InvalidInputArgument = 102;
        const CantDeleteMessage    = 116;
    }

    class Account
    {
        public function __construct(public string $email = 'user@example.com') {}
        public function Email(): string { return $this->email; }
        public function IncLogin(): string { return $this->email; }
        public function ParentEmailHelper(): string { return $this->email; }
    }

    /** Stands in for \RainLoop\Actions: only the three calls the plugin makes. */
    class Actions
    {
        public array $params = [];
        public ?Account $account = null;
        public ?Settings $settings = null;
        /** When true, SettingsProvider() throws — the "no mapping available" path. */
        public bool $settingsExplode = false;

        public array $storage = [];

        public function GetActionParam(string $key, $default = null) { return $this->params[$key] ?? $default; }
        public function getAccountFromToken(bool $throw = true): ?Account { return $this->account; }

        /** An in-memory stand-in for the per-account key/value store. */
        public function StorageProvider(bool $local = false): object
        {
            return new class ($this) {
                public function __construct(private Actions $a) {}
                public function Get($account, int $type, string $key) { return $this->a->storage[$key] ?? null; }
                public function Put($account, int $type, string $key, string $value): bool
                { $this->a->storage[$key] = $value; return true; }
            };
        }
        public function GetAccount(): ?Account { return $this->account; }

        public function SettingsProvider(bool $local = false): object
        {
            if ($this->settingsExplode) {
                throw new \RuntimeException('settings backend unavailable');
            }
            $settings = $this->settings;
            return new class ($settings) {
                public function __construct(private ?Settings $s) {}
                public function Load($account): ?Settings { return $this->s; }
            };
        }
    }
}

namespace RainLoop\Model {
    class Account
    {
        public function __construct(private string $email = 'user@example.com') {}
        public function Email(): string { return $this->email; }
        public function IncLogin(): string { return $this->email; }
        public function ParentEmailHelper(): string { return $this->email; }
    }
    class Identity
    {
        public function __construct(private string $email = '', private string $name = '') {}
        public function Email(): string { return $this->email; }
        public function Name(): string { return $this->name; }
    }
}

namespace MailSo\Sieve {
    class SieveClient {}
}

namespace MailSo\Imap {
    class ImapClient {}
    class FolderCollection extends \ArrayObject {}
}

namespace RainLoop\Providers\Storage\Enumerations {
    class StorageType { const CONFIG = 1; const USER = 2; }
}

namespace SnappyMail {
    class Log
    {
        public static array $lines = [];
        public static function notice(string $tag, string $msg): void { self::$lines[] = [$tag, $msg]; }
        public static function warning(string $tag, string $msg): void { self::$lines[] = [$tag, $msg]; }
    }
}

namespace RainLoop\Exceptions {
    class ClientException extends \RuntimeException
    {
        public function __construct(public int $code_ = 0, $previous = null, public string $message_ = '')
        {
            parent::__construct($message_, $code_);
        }
    }
}

namespace RainLoop\Enumerations {
    class PluginPropertyType { const BOOL = 1; const STRING = 2; const INT = 3; }
}

namespace RainLoop\Plugins {
    class Property
    {
        private array $data = [];
        public function __construct(public string $name) {}
        public static function NewInstance(string $name): self { return new self($name); }
        public function SetLabel(string $v): self { $this->data['label'] = $v; return $this; }
        public function SetDescription(string $v): self { $this->data['desc'] = $v; return $this; }
        public function SetType(int $v): self { $this->data['type'] = $v; return $this; }
        public function SetDefaultValue($v): self { $this->data['default'] = $v; return $this; }
        public function GetDefaultValue() { return $this->data['default'] ?? null; }
    }

    /** Records what the plugin registers, so Init() can be asserted on. */
    abstract class AbstractPlugin
    {
        public array $hooks = [];
        public array $css   = [];
        public array $config = [];
        public ?\RainLoop\Actions $actionsStub = null;

        public function addHook(string $event, string $method): void { $this->hooks[$event] = $method; }
        public function addCss(string $file): void { $this->css[] = $file; }
        public function addJs(string $file): void {}
        public function addTemplate(string $file): void {}
        public function addJsonHook(string $name, string $method): void { $this->hooks['json:' . $name] = $method; }
        public function Path(): string { return __DIR__ . '/..'; }

        public function Config(): object
        {
            $cfg = $this->config;
            return new class ($cfg) {
                public function __construct(private array $c) {}
                public function Get(string $section, string $key, $default = null) { return $this->c[$key] ?? $default; }
            };
        }

        public function Manager(): object
        {
            $actions = $this->actionsStub ?? new \RainLoop\Actions();
            return new class ($actions) {
                public function __construct(private \RainLoop\Actions $a) {}
                public function Actions(): \RainLoop\Actions { return $this->a; }
            };
        }
    }
}
