<?php

/*
 * Rammeverkskatalogen: the standards and regulatory frameworks Procynia knows by a stable id, for any
 * module that lets a customer name the frameworks a piece of work follows (read through
 * App\Support\FrameworkCatalog, never directly).
 *
 * The id is stored on customer data (e.g. management_reviews.frameworks) and must never be renamed or
 * removed — a historical record would lose its name. Retire an entry from a module by dropping that
 * module from its 'modules' list instead.
 *
 * 'reference' is the framework's own designation and is shown as is in every language; the readable
 * name, the domain and the type are translated (lang: framework_catalog.*). 'version' is the edition a
 * module's own mapping was written against; a module that records it (Ledelsens gjennomgåelse does when
 * a review is finalized) keeps what an old record was checked against.
 *
 * Being in the catalog says nothing about requirement coverage. Whether a module can say which of a
 * framework's requirements a piece of work covers is that module's own mapping (for Ledelsens
 * gjennomgåelse: config/management_review.php 'framework_coverage'), and whether that mapping is
 * professionally verified is stated there.
 */
return [

    'types' => ['standard', 'regulation'],

    'catalog' => [
        'iso9001' => ['reference' => 'ISO 9001', 'version' => '2015', 'type' => 'standard', 'domain' => 'quality', 'modules' => ['management_review']],
        'iso27001' => ['reference' => 'ISO/IEC 27001', 'version' => '2022', 'type' => 'standard', 'domain' => 'information_security', 'modules' => ['management_review']],
        'iso14001' => ['reference' => 'ISO 14001', 'version' => '2015', 'type' => 'standard', 'domain' => 'environment', 'modules' => ['management_review']],
        'iso45001' => ['reference' => 'ISO 45001', 'version' => '2018', 'type' => 'standard', 'domain' => 'occupational_health_safety', 'modules' => ['management_review']],
        'iso22301' => ['reference' => 'ISO 22301', 'version' => '2019', 'type' => 'standard', 'domain' => 'business_continuity', 'modules' => ['management_review']],
        'iso20000_1' => ['reference' => 'ISO/IEC 20000-1', 'version' => '2018', 'type' => 'standard', 'domain' => 'service_management', 'modules' => ['management_review']],
        'nis2' => ['reference' => 'NIS2', 'version' => '2022/2555', 'type' => 'regulation', 'domain' => 'cybersecurity', 'modules' => ['management_review']],
        'dora' => ['reference' => 'DORA', 'version' => '2022/2554', 'type' => 'regulation', 'domain' => 'digital_resilience', 'modules' => ['management_review']],
    ],

];
