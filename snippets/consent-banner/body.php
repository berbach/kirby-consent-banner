<?php
/**
 * Banner data and script of the consent banner, before </body>:
 *
 *   <?php snippet('consent-banner/body') ?>
 *
 * Only needed with the option bb.consent-banner.inject set to false;
 * otherwise the plugin adds both to every page by itself.
 */
echo consentBanner()->body();
