<?php
/**
 * Template Name: Landing Valoración
 *
 * Uses unified nvx-brand-hero pattern with banner for consistency.
 * Valuation-specific content and HubSpot form integration.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

$clinics = function_exists( 'nvx_schema_clinics' ) ? nvx_schema_clinics() : array();
$config  = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

$chamberi_phone = ! empty( $clinics['chamberi']['telephone'] ) ? (string) $clinics['chamberi']['telephone'] : '+34669319836';
$goya_phone     = ! empty( $clinics['goya']['telephone'] ) ? (string) $clinics['goya']['telephone'] : '+34647505107';

$chamberi_wa = ! empty( $config['chamberi']['whatsapp_href'] ) ? $config['chamberi']['whatsapp_href'] : 'https://wa.me/' . preg_replace( '/\D/', '', $chamberi_phone );
$goya_wa     = ! empty( $config['goya']['whatsapp_href'] ) ? $config['goya']['whatsapp_href'] : 'https://wa.me/' . preg_replace( '/\D/', '', $goya_phone );

// Global flag to prevent duplicate hero media injection from nvx_ensure_hero_featured_media
global $nvx_page_shell_has_hero;
$nvx_page_shell_has_hero = true;

ob_start();

if ( function_exists( 'nvx_is_valoracion_page_request' ) && nvx_is_valoracion_page_request() ) {
	echo '<div class="entry-content nvx-page__content">';
	the_content();
	echo '</div>';
	$content = ob_get_clean();
	set_query_var( 'nvx_shell_content', $content );
	set_query_var( 'nvx_shell_skip_header', true );
	get_template_part( 'template-parts/content/nvx-page-shell' );
	return;
}
?>

<!-- Content goes inside .nvx-brand-page wrapper from header.php -->
<section class="nvx-brand-hero" aria-labelledby="nvx-valoracion-hero-title">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'VALORACIÓN MÉDICA · NUVANX MADRID', 'nuvanx-medical' ); ?></p>
				<h1 id="nvx-valoracion-hero-title" class="nvx-brand-hero__title"><?php esc_html_e( 'Valoración médica estética personalizada en Madrid', 'nuvanx-medical' ); ?></h1>
				<p class="nvx-brand-hero__lead">
					<?php esc_html_e( 'Da el siguiente paso con una valoración médica personalizada. Diagnosticamos tu caso, explicamos las opciones y diseñamos un plan individualizado. Sin compromiso, sin presión.', 'nuvanx-medical' ); ?>
				</p>
				<div class="nvx-brand-actions">
					<a href="#nvx-hubspot-form" class="nvx-brand-btn nvx-brand-btn--primary">
						<?php esc_html_e( 'Iniciar mi valoración médica', 'nuvanx-medical' ); ?>
					</a>
					<a href="<?php echo esc_url( $chamberi_wa ); ?>" class="nvx-brand-btn nvx-brand-btn--secondary" rel="noopener noreferrer" target="_blank">
						<?php esc_html_e( 'Contactar por WhatsApp', 'nuvanx-medical' ); ?>
					</a>
					<a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $chamberi_phone ) ); ?>" class="nvx-brand-btn nvx-brand-btn--secondary">
						<?php echo esc_html( sprintf( __( 'Llamar: %s', 'nuvanx-medical' ), $chamberi_phone ) ); ?>
					</a>
				</div>
				<p class="nvx-brand-meta">
					<?php esc_html_e( 'Diagnóstico individual · Indicación proporcionada · Seguimiento médico', 'nuvanx-medical' ); ?>
				</p>
			</div>
		</div>
	</section>

		<section class="nvx-brand-section" aria-label="<?php esc_attr_e( 'Proceso de valoración', 'nuvanx-medical' ); ?>">
			<div class="nvx-brand-section__inner">
				<p class="nvx-brand-kicker" style="text-transform: uppercase;"><?php esc_html_e( 'Nuestro método', 'nuvanx-medical' ); ?></p>
				<h2 class="nvx-brand-title"><?php esc_html_e( 'Cómo funciona la valoración', 'nuvanx-medical' ); ?></h2>
				<p class="nvx-brand-lead">
					<?php esc_html_e( 'Plan individualizado • Precisión clínica • Recuperación según tu caso', 'nuvanx-medical' ); ?>
				</p>

				<div class="nvx-brand-grid nvx-brand-grid--4">
					<div class="nvx-brand-card">
						<div class="nvx-brand-card__number">01</div>
						<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Escuchar', 'nuvanx-medical' ); ?></h3>
						<p class="nvx-body">
							<?php esc_html_e( 'Definimos qué cambio buscas y qué resultado consideras proporcionado.', 'nuvanx-medical' ); ?>
						</p>
					</div>

					<div class="nvx-brand-card">
						<div class="nvx-brand-card__number">02</div>
						<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Explorar', 'nuvanx-medical' ); ?></h3>
						<p class="nvx-body">
							<?php esc_html_e( 'Revisamos estructura, grasa, laxitud, superficie, fototipo y antecedentes.', 'nuvanx-medical' ); ?>
						</p>
					</div>

					<div class="nvx-brand-card">
						<div class="nvx-brand-card__number">03</div>
						<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Documentar', 'nuvanx-medical' ); ?></h3>
						<p class="nvx-body">
							<?php esc_html_e( 'Explicamos técnica, fases, cuidados, seguimiento y presupuesto individualizado.', 'nuvanx-medical' ); ?>
						</p>
					</div>

					<div class="nvx-brand-card">
						<div class="nvx-brand-card__number">04</div>
						<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Planificar', 'nuvanx-medical' ); ?></h3>
						<p class="nvx-body">
							<?php esc_html_e( 'Diseñamos un plan adaptado a tus necesidades, expectativas y límites clínicos.', 'nuvanx-medical' ); ?>
						</p>
					</div>
				</div>
			</div>
		</section>
	</div>
	<div class="entry-content nvx-page__content">
		<?php the_content(); ?>
	</div>

<?php
$content = ob_get_clean();

set_query_var( 'nvx_shell_content', $content );
set_query_var( 'nvx_shell_skip_header', true );
get_template_part( 'template-parts/content/nvx-page-shell' ); ?>
