<?php
/** A public custom form or registration form, under the business's own name. */
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
$f   = $ctx['form'];
$biz = $f['business'];
nw_head( $f['title'] . ' · ' . $biz['name'] );
?>
<style>:root{--brand:<?php echo esc_html( $biz['color'] ); ?>}</style>
</head>
<body class="nw-public<?php echo $ctx['embed'] ? ' nw-embed' : ''; ?>">
<main class="book-wrap">
	<?php if ( ! $ctx['embed'] ) : ?>
		<div class="book-head">
			<?php if ( $biz['logo_url'] ) : ?><img class="book-logo" src="<?php echo esc_url( $biz['logo_url'] ); ?>" alt=""><?php endif; ?>
			<div><p class="muted small" style="margin:0"><?php echo esc_html( $biz['name'] ); ?></p><h1><?php echo esc_html( $f['title'] ); ?></h1></div>
		</div>
	<?php endif; ?>
	<?php if ( $f['description'] ) : ?><p><?php echo nl2br( esc_html( $f['description'] ) ); ?></p><?php endif; ?>
	<?php if ( 'registration' === $f['kind'] && ( $f['event_start'] || $f['location'] ) ) : ?>
		<p class="muted"><?php echo esc_html( trim( $f['event_start'] . ( $f['event_end'] ? ' to ' . $f['event_end'] : '' ) . ( $f['location'] ? ' · ' . $f['location'] : '' ) ) ); ?><?php if ( null !== $f['spots_left'] ) : ?><br><?php echo (int) $f['spots_left']; ?> places left<?php endif; ?></p>
	<?php endif; ?>
	<?php if ( ! in_array( $f['state'], array( 'open', 'waitlist' ), true ) ) : ?>
		<section class="card card-pad"><p><?php echo esc_html( array( 'closed' => 'This form is closed.', 'not_open' => 'Registration has not opened yet.', 'full' => 'This event is full.' )[ $f['state'] ] ?? 'This form is closed.' ); ?></p></section>
	<?php else : ?>
		<?php if ( 'waitlist' === $f['state'] ) : ?><p class="alert alert-warn">The event is full. You can join the waitlist below.</p><?php endif; ?>
		<form id="pub-form" class="card card-pad" novalidate data-slug="<?php echo esc_attr( $f['slug'] ); ?>" enctype="multipart/form-data">
			<?php
			foreach ( $f['fields'] as $x ) :
				$req  = $x['required'] ? ' required' : '';
				$name = esc_attr( $x['key'] );
				$cond = isset( $x['show_if'] ) ? ' data-if="' . esc_attr( $x['show_if']['field'] ) . '" data-eq="' . esc_attr( $x['show_if']['equals'] ) . '"' : '';
				echo '<div class="ff"' . $cond . '>'; // phpcs:ignore
				if ( 'checkbox' === $x['type'] ) :
					?>
					<label class="check"><input type="checkbox" name="<?php echo $name; // phpcs:ignore ?>"<?php echo $req; // phpcs:ignore ?>> <span><?php echo esc_html( $x['label'] ); ?></span></label>
				<?php elseif ( 'choice' === $x['type'] ) : ?>
					<fieldset class="field"><legend><?php echo esc_html( $x['label'] . ( $x['required'] ? ' *' : '' ) ); ?></legend>
					<?php foreach ( $x['options'] as $o ) : ?><label class="check"><input type="radio" name="<?php echo $name; // phpcs:ignore ?>" value="<?php echo esc_attr( $o ); ?>"> <span><?php echo esc_html( $o ); ?></span></label><?php endforeach; ?>
					</fieldset>
				<?php else : ?>
					<label class="field"><span><?php echo esc_html( $x['label'] . ( $x['required'] ? ' *' : '' ) ); ?></span>
					<?php if ( 'textarea' === $x['type'] ) : ?>
						<textarea name="<?php echo $name; // phpcs:ignore ?>" rows="3"<?php echo $req; // phpcs:ignore ?>></textarea>
					<?php elseif ( 'dropdown' === $x['type'] ) : ?>
						<select name="<?php echo $name; // phpcs:ignore ?>"<?php echo $req; // phpcs:ignore ?>><option value="">Choose one</option><?php foreach ( $x['options'] as $o ) : ?><option><?php echo esc_html( $o ); ?></option><?php endforeach; ?></select>
					<?php else : ?>
						<input name="<?php echo $name; // phpcs:ignore ?>" type="<?php echo esc_attr( array( 'phone' => 'tel', 'file' => 'file' )[ $x['type'] ] ?? $x['type'] ); ?>"<?php echo $req; // phpcs:ignore ?>>
					<?php endif; ?>
					</label>
				<?php endif; ?>
				<?php if ( ! empty( $x['help'] ) ) : ?><p class="muted small"><?php echo esc_html( $x['help'] ); ?></p><?php endif; ?>
				<?php
				echo '</div>';
			endforeach;
			?>
			<label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
			<?php if ( $f['privacy_note'] ) : ?><p class="muted small"><?php echo esc_html( $f['privacy_note'] ); ?></p><?php endif; ?>
			<div class="form-error" role="alert" hidden></div>
			<button class="btn btn-brand btn-block" type="submit"><?php echo 'waitlist' === $f['state'] ? 'Join the waitlist' : ( 'registration' === $f['kind'] ? 'Register' : 'Submit' ); ?></button>
		</form>
		<section id="pub-done" class="card card-pad" hidden role="status"></section>
	<?php endif; ?>
</main>
<footer class="book-foot">Powered by <a href="https://neondigital.in" target="_blank" rel="noopener">NeonDigital.in</a></footer>
<?php nw_config( $ctx['config'] ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/public-form.js' ) ); ?>"></script>
</body></html>
