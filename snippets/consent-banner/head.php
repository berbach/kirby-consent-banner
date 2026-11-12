<?php
/**
 * Stylesheet and tracking bootstrap of the consent banner, for the <head>:
 *
 *   <?php snippet('consent-banner/head') ?>
 *
 * Only needed with the option bb.consent-banner.inject set to false;
 * otherwise the plugin adds both to every page by itself.
 */
echo consentBanner()->head();
