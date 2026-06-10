<?php
/**
 * /carne/<token>: verificación pública del carné. No expone datos sensibles.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$token = isset( $token ) ? (string) $token : '';
$uid   = Cead_Acad_Account::verify_token( $token );

$ok     = false;
$name   = '';
$rdisp  = '';
$course = '';
$avatar = '';

if ( $uid ) {
	$u = get_user_by( 'id', $uid );
	if ( $u ) {
		$ok     = true;
		$name   = $u->display_name;
		$roles  = Cead_Acad_Capabilities::roles();
		$role   = cead_acad_user_role( $uid );
		$rdisp  = $roles[ $role ]['display'] ?? $role;
		$avatar = Cead_Acad_Account::avatar_url( $uid, 'medium' );
		$cid    = (int) get_user_meta( $uid, '_cead_acad_current_course_id', true );
		if ( ! $cid && class_exists( 'Cead_Acad_Courses_Roster' ) ) {
			$cs = Cead_Acad_Courses_Roster::courses_for_user( $uid );
			if ( $cs ) { $cid = (int) $cs[0]; }
		}
		$course = $cid ? get_the_title( $cid ) : '';
	}
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( $ok ? __( 'Carné verificado', 'cead-acad' ) : __( 'Carné no válido', 'cead-acad' ) ); ?> · <?php bloginfo( 'name' ); ?></title>
	<style>
		body{margin:0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#F7F5F0;color:#0B0B0B;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:1.5rem}
		.v{background:#fff;border:1px solid #E4E1DA;border-top:6px solid <?php echo $ok ? '#5FAE6B' : '#E93B3C'; ?>;border-radius:16px;max-width:380px;width:100%;padding:2rem;text-align:center;box-shadow:0 18px 50px rgba(0,0,0,.10)}
		.v-badge{font-size:2.6rem;line-height:1}
		.v-state{font-weight:800;text-transform:uppercase;letter-spacing:.02em;color:<?php echo $ok ? '#3F8C4F' : '#B82A2B'; ?>;margin:.4rem 0 1.2rem}
		.v-photo{width:104px;height:104px;border-radius:50%;object-fit:cover;margin:0 auto;display:block;border:3px solid #E4E1DA}
		.v-photo--ph{display:flex;align-items:center;justify-content:center;background:#0B0B0B;color:#fff;font-weight:800;font-size:2rem}
		.v-name{font-size:1.4rem;margin:.9rem 0 .15rem}
		.v-role{color:#8E8E8E;font-weight:700;text-transform:uppercase;font-size:.8rem;letter-spacing:.03em}
		.v-course{margin-top:.5rem;color:#4A4A4A}
		.v-org{margin-top:1.4rem;font-weight:800;letter-spacing:.3em;color:#E93B3C}
		.v-foot{margin-top:.4rem;color:#8E8E8E;font-size:.78rem}
	</style>
</head>
<body>
	<div class="v">
		<?php if ( $ok ) : ?>
			<div class="v-badge" aria-hidden="true">✅</div>
			<div class="v-state"><?php esc_html_e( 'Carné válido', 'cead-acad' ); ?></div>
			<?php if ( $avatar ) : ?>
				<img class="v-photo" src="<?php echo esc_url( $avatar ); ?>" alt="">
			<?php else : ?>
				<div class="v-photo v-photo--ph"><?php echo esc_html( Cead_Acad_Account::initials( $name ) ); ?></div>
			<?php endif; ?>
			<h1 class="v-name"><?php echo esc_html( $name ); ?></h1>
			<div class="v-role"><?php echo esc_html( $rdisp ); ?></div>
			<?php if ( $course ) : ?><div class="v-course"><?php echo esc_html( $course ); ?></div><?php endif; ?>
		<?php else : ?>
			<div class="v-badge" aria-hidden="true">⛔</div>
			<div class="v-state"><?php esc_html_e( 'No verificado', 'cead-acad' ); ?></div>
			<p><?php esc_html_e( 'Este carné no es válido o el enlace expiró.', 'cead-acad' ); ?></p>
		<?php endif; ?>
		<div class="v-org">CEAD</div>
		<div class="v-foot"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
	</div>
</body>
</html>
<?php
