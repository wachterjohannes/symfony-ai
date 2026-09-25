Creating Mate Extensions
========================

Symfony AI Mate (``vendor/bin/mate``) is a CLI that gives coding agents project-aware tools for a
PHP application: the compiled container, the profiler and the logs.

A Mate extension is a Composer package that declares itself through an ``extra.ai-mate`` section
in its ``composer.json``, similar to a PHPStan extension. It can ship tools, resources and
skills.

.. tip::

    The `matesofmate/extension-template`_ repository is a ready-made starting point.

Quick Start
-----------

1. Configure composer.json
~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: json

    {
        "name": "vendor/my-extension",
        "type": "library",
        "require": {
            "symfony/ai-mate": "^0.14"
        },
        "extra": {
            "ai-mate": {
                "scan-dirs": ["src"],
                "skills": ["skills"]
            }
        }
    }

The ``extra.ai-mate`` section is what makes the package an extension. Create the ``skills``
directory next to ``composer.json``. `Shipping Skills`_ explains what belongs in it.

2. Create Capabilities
~~~~~~~~~~~~~~~~~~~~~~

Mark public methods with the Mate attributes. Mate finds them by reflection and derives the JSON
input schema from the method signature plus the ``@param`` PHPDoc::

    namespace Vendor\MyExtension;

    use Psr\Log\LoggerInterface;
    use Symfony\AI\Mate\Attribute\MateTool;

    class MyTool
    {
        public function __construct(
            private LoggerInterface $logger,
        ) {
        }

        /**
         * @param string $param The value to process
         */
        #[MateTool(name: 'my-tool', title: 'My Tool', description: 'What this tool does')]
        public function execute(string $param): string
        {
            $this->logger->info('Tool executed', ['param' => $param]);

            return 'Result: '.$param;
        }
    }

Three attributes are available, all in ``Symfony\AI\Mate\Attribute``:

``#[MateTool]``
    A method the agent calls with arguments. Parameters: ``name``, ``title``, ``description``.

``#[MateResource]``
    Data the agent addresses by a fixed URI. Parameters: ``uri``, ``name``, ``title``,
    ``description``, ``mimeType``.

``#[MateResourceTemplate]``
    Data addressed by a URI pattern. The variables of ``uriTemplate`` are passed to the method.
    Parameters: ``uriTemplate``, ``name``, ``title``, ``description``, ``mimeType``.

A tool must return a scalar value or an array. An object fails when the result is encoded.

.. note::

    These are Mate's own attributes. They are unrelated to the Agent component's
    ``Symfony\AI\Agent\Toolbox\Attribute\AsTool``.

3. Install and Verify
~~~~~~~~~~~~~~~~~~~~~

.. code-block:: terminal

    $ composer require --dev vendor/my-extension
    $ vendor/bin/mate debug:capabilities --extension=vendor/my-extension

In a project that ran ``mate init``, the Composer plugin runs ``mate discover`` after the install.
That adds the extension to ``mate/extensions.php``, enabled by default. Without the plugin, run
``vendor/bin/mate discover`` yourself.

If a tool is missing, see :doc:`troubleshooting`.

Dependency Injection
--------------------

Tools and resources are services in Mate's own DI container. Constructor dependencies are
autowired. To configure services, list one or more PHP service files under ``includes``:

.. code-block:: json

    {
        "extra": {
            "ai-mate": {
                "scan-dirs": ["src"],
                "includes": ["config/services.php"]
            }
        }
    }

The files use the standard Symfony DI format::

    // config/services.php
    use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
    use Vendor\MyExtension\MyApiClient;

    return static function (ContainerConfigurator $container): void {
        $container->parameters()
            ->set('my_extension.base_url', 'https://api.example.com');

        $container->services()
            ->set(MyApiClient::class)
                ->arg('$apiKey', '%env(MY_API_KEY)%')
                ->arg('$baseUrl', '%my_extension.base_url%');
    };

Expose what a project may want to change as a parameter, prefixed with the name of your
extension. A project overrides it in its ``mate/config.php``. ``%mate.root_dir%`` holds the root
directory of the project.

Designing Tools for Agents
--------------------------

The context window of an agent is limited and expensive. A tool should distill its data source,
not relay it.

* **Return what changes the diagnosis.** Prefer counts over full lists when counts tell the story.
  Drop fields the agent cannot act on.
* **Limit unbounded results.** Logs, queries and services need a hard upper limit and a
  ``truncated`` flag, so the agent knows it sees a sample and can ask again with a narrower
  filter.
* **Keep sensitive data out.** Passwords, tokens, auth headers and API keys must be omitted or
  redacted. Omit a field when it is not needed for the diagnosis.
* **Format for reasoning.** Use readable strings over numeric codes, rounded values with
  consistent units, and flat structures.
* **Split triage from detail.** A tool that lists answers "is this relevant?". A resource the
  agent drills into answers "what exactly went wrong?". The profiler tools of the Symfony
  extension work this way, see :doc:`extensions`.

Returning Application Data
~~~~~~~~~~~~~~~~~~~~~~~~~~

Data captured from the inspected application can contain text that end users or third-party
packages control: log messages, URLs, SQL, request payloads. An agent must not read that text as
instructions. Wrap such a payload with ``ResponseEncoder::encodeUntrusted()``::

    use Symfony\AI\Mate\Attribute\MateTool;
    use Symfony\AI\Mate\Encoding\ResponseEncoder;

    class OrderLogTool
    {
        #[MateTool(name: 'my-order-log', description: 'List the latest order log entries')]
        public function latest(int $limit = 20): string
        {
            // ...

            return ResponseEncoder::encodeUntrusted(['entries' => $entries, 'truncated' => $truncated]);
        }
    }

The payload ends up under an ``untrusted_data`` key, next to a ``_security_notice`` that tells the
agent how to treat it. See :ref:`mate-untrusted-data`.

Configuration Reference
-----------------------

All keys live under ``extra.ai-mate`` in ``composer.json``. All of them are optional, and all paths
are relative to the package root.

``scan-dirs``
    List of directories to scan for Mate attributes. Without it no class is scanned, so an
    extension that ships tools or resources needs this key.

``includes``
    List of PHP service configuration files in the Symfony DI format. Environment variables are
    available through ``%env()%``.

``skills``
    List of directories that hold `Agent Skills`_. A single string is accepted as well. By
    convention one ``skills`` directory.

``extension``
    Default ``true``. Set it to ``false`` to keep the package out of discovery. Use it for
    applications and internal tooling packages that use Mate but are no extension. ``mate init``
    writes it into an application.

``instructions``
    Deprecated since 0.15 and ignored. It pointed at an ``INSTRUCTIONS.md`` file that was
    aggregated into ``mate/AGENT_INSTRUCTIONS.md``, which Mate no longer generates. Move that
    guidance into the skill of the tools it describes.

A complete example:

.. code-block:: json

    {
        "extra": {
            "ai-mate": {
                "scan-dirs": ["src"],
                "includes": ["config/services.php"],
                "skills": ["skills"]
            }
        }
    }

Shipping Skills
---------------

Skills are how your tools reach an agent. A skill describes a whole task: which tools to use, in
what order, and how to read the results. Each immediate subdirectory of a skills directory is one skill
and must contain a ``SKILL.md`` file::

    skills/
    └── my-skill/
        ├── SKILL.md
        └── references/
            └── details.md

When a project runs ``mate discover``, each skill is copied into ``.agents/skills/mate-my-skill/``
and mirrored into ``.claude/skills/``. See :doc:`skills` for what happens on the project side.

Two things decide whether a skill works:

* **The description.** It is all an agent has when it decides whether to load the skill. Say what
  the skill does and when it applies.
* **The links.** The whole skill directory is copied, and nothing outside of it. A Markdown link
  in ``SKILL.md`` must therefore point at a file inside the skill directory.

Every tool of the extension should be covered by a skill. If a tool replaces a command the agent
already knows, such as ``bin/console debug:container`` or ``tail`` on a log file, say so in the
skill: an agent keeps to the familiar command unless it learns why the tool is better.

``vendor/bin/mate skills:validate`` checks both in a project that has your extension installed.

Publishing an Extension
-----------------------

Publish the package on Packagist like any other Composer package. Then add it to `awesome-mate`_,
the curated list of Mate extensions, so that others find it. The `MatesOfMate`_ organisation also
welcomes extensions that should be maintained by the community.

.. _`matesofmate/extension-template`: https://github.com/matesofmate/extension-template
.. _`awesome-mate`: https://github.com/MatesOfMate/awesome-mate
.. _`MatesOfMate`: https://github.com/MatesOfMate
.. _`Agent Skills`: https://agentskills.io
