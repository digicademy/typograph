..  _field-transforms:

======================
Field Value Transforms
======================

..  contents::
    :local:

Field value transforms run on raw database values *after* they are fetched
and *before* they are handed to the GraphQL layer. They allow site-specific
post-processing of individual fields without leaking the processing logic
into the schema or into client code.

The primary use case is resolving TYPO3 link-handling URIs (``t3://page``,
``t3://file``, ``t3://url``, ``mailto:``, ``tel:``) that RTE-managed fields
such as ``tt_content.bodytext`` store verbatim in the database. In a normal
Fluid render, ``lib.parseFunc_RTE`` resolves these to real URLs during
page rendering. TypoGraph bypasses the TSFE-driven rendering pipeline, so
without a transform the raw ``t3://`` URIs would reach the client as-is.

..  _h2-configuration-field-transforms-structure:

Configuration Structure
=======================

Transforms are configured under the ``typograph.fieldTransforms`` key in
the site configuration, nested as ``<TypeName>: <fieldName>: <transformName>``:

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      fieldTransforms:
        TypeName:
          fieldName: transformName

Keys follow GraphQL naming: ``TypeName`` matches a type in your schema
(e.g. ``Content``) and ``fieldName`` matches a field on that type
(e.g. ``bodytext``). Internally the resolver converts the field name to
the corresponding database column name via
``camelCaseToLowerCaseUnderscored``, the same conversion used everywhere
else in TypoGraph.

..  _h2-configuration-field-transforms-built-in:

Built-in Transforms
===================

..  _h3-configuration-field-transforms-typolinks:

typolinks
---------

Expands TYPO3 link-handling URIs in HTML ``href`` attributes into real
URLs. The following schemes are rewritten:

*   ``t3://page?uid=<id>[#<fragment>]``
*   ``t3://file?uid=<id>``
*   ``t3://file?identifier=<storage>:<path>``
*   ``t3://url?url=<target>``
*   ``t3://record?identifier=<key>&uid=<id>``
*   ``mailto:<address>``
*   ``tel:<number>``

Any other scheme (plain ``http``/``https``, in-page anchors, protocol-relative
URLs) is left untouched so that user-pasted external links remain valid.

Resolution uses TYPO3's
:php:`TYPO3\\CMS\\Frontend\\Typolink\\LinkFactory`, which internally
dispatches to the same link builders (``PageLinkBuilder``,
``FileOrFolderLinkBuilder``, ``EmailLinkBuilder``, ``TelephoneLinkBuilder``,
``ExternalUrlLinkBuilder``, ``DatabaseRecordLinkBuilder``) used during
regular frontend rendering.

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      fieldTransforms:
        Content:
          bodytext: typolinks

With this configuration, a row whose ``bodytext`` contains::

    <p>See <a href="t3://page?uid=76#64">this page</a> for details.</p>

will be delivered to the GraphQL client as::

    <p>See <a href="/path/to/page/#c64">this page</a> for details.</p>

..  _h2-configuration-field-transforms-tsfe-caveats:

TSFE-Related Caveats
====================

Because TypoGraph's middleware runs *before*
``typo3/cms-frontend/page-resolver``, there is no running
``TypoScriptFrontendController``. ``LinkFactory`` copes with this by
lazily building a minimal TSFE from the request attributes attached by
the earlier ``typo3/cms-frontend/site`` middleware (``site``, ``language``,
``routing``). This is sufficient for all built-in link types.

TypoScript-driven link behaviour such as ``config.linkVars``,
``config.forceAbsoluteUrls``, or custom ``typolinkBuilder`` entries is
*not* applied, because the full TypoScript setup is never loaded. If
your site relies on any of those, configure the equivalent at a
different level (e.g. absolute URLs via Site configuration).

Full ``lib.parseFunc_RTE`` processing (paragraph wrapping, ``<br>``
handling, HTML-sanitizer integration, …) is not available from this
middleware position because it depends on the TypoScript setup array
being populated by the regular frontend pipeline. The ``typolinks``
transform deliberately scopes itself to link rewriting for this reason.

..  _h2-configuration-field-transforms-custom:

Registering Custom Transforms
=============================

To add your own transform, implement
:php:`Digicademy\\TypoGraph\\Transformer\\TransformerInterface` and
register it under a short name in ``Configuration/Services.yaml``:

..  code-block:: php
    :caption: Classes/Transformer/MyCustomTransformer.php

    <?php

    declare(strict_types=1);

    namespace Vendor\Sitepackage\Transformer;

    use Digicademy\TypoGraph\Transformer\TransformerInterface;
    use Psr\Http\Message\ServerRequestInterface;

    final class MyCustomTransformer implements TransformerInterface
    {
        public function transform(mixed $value, ServerRequestInterface $request): mixed
        {
            // return $value unchanged when it is not of a type you handle
            if (!is_string($value)) {
                return $value;
            }
            // …your post-processing…
            return $value;
        }
    }

..  code-block:: yaml
    :caption: Configuration/Services.yaml (sitepackage)

    services:
      _defaults:
        autowire: true
        autoconfigure: true
        public: false

      Vendor\Sitepackage\:
        resource: '../Classes/*'

      # Extend the TransformerRegistry provided by TypoGraph with the
      # new transform under a short name. Keep the `typolinks` entry so
      # the built-in transform remains available alongside yours.
      Digicademy\TypoGraph\Transformer\TransformerRegistry:
        arguments:
          $transformers:
            typolinks: '@Digicademy\TypoGraph\Transformer\TypolinksTransformer'
            myCustom: '@Vendor\Sitepackage\Transformer\MyCustomTransformer'

Transforms must:

*   Return the input unchanged for value types they do not handle.
*   Avoid throwing for unexpected inputs. The resolver logs and swallows
    exceptions so that one misbehaving transform does not abort the
    entire GraphQL response, but logging contributes noise; prefer a
    defensive early return.

..  _h2-configuration-field-transforms-error-behaviour:

Error Behaviour
===============

*   If a configured transform name has no entry in the registry, the
    resolver logs a warning and leaves the raw value in place. This is
    usually a typo in site configuration or a missing DI wiring.
*   If a transform throws, the resolver logs an error and leaves the
    raw value in place for the affected record.
*   If the current request is not attached (only possible when calling
    :php:`ResolverService::process()` directly without a request),
    transforms are skipped entirely.
