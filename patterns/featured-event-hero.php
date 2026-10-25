<?php
/**
 * Featured event hero pattern: single large card with RSVP button.
 *
 * @package event-hub
 */

/**
 * Pattern content. The Title/Slug header lives in Plugin::register_patterns()
 * because plugin patterns are registered programmatically (WP core only
 * auto-scans theme patterns/ dirs). The eventId attribute is meant to be
 * replaced by the editor after insertion (0 = first upcoming event fallback
 * is handled by Render_Blocks::summary()).
 */
?>
<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide">
	<!-- wp:event-hub/event-card {"eventId":0} /-->
	<!-- wp:event-hub/rsvp-button {"eventId":0} /-->
</div>
<!-- /wp:group -->
