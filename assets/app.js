/*
 * Peyra's single entrypoint. The stylesheets are linked by templates/base.html.twig (px.css for the
 * screen, print.css for print), not imported here, so that each keeps its media attribute.
 */
import * as Turbo from '@hotwired/turbo';
import './stimulus_bootstrap.js';

// design-ux.md § 6.4: the progress bar appears after 300 ms, not Turbo's default 500.
Turbo.config.drive.progressBarDelay = 300;
