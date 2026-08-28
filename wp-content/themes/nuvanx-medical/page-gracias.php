<?php
/**
 * Managed post-conversion confirmation page.
 *
 * WordPress resolves this template directly for the published /gracias/ page,
 * so historical CMS HTML is not part of the runtime rendering path.
 *
 * @package NUVANX_Medical
 */

defined( 'ABSPATH' ) || exit;

$nvx_clinics  = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
$nvx_chamberi = isset( $nvx_clinics['chamberi'] ) && is_array( $nvx_clinics['chamberi'] )
	? $nvx_clinics['chamberi']
	: array( 'whatsapp_href' => 'https://wa.me/34669319836' );
$nvx_goya     = isset( $nvx_clinics['goya'] ) && is_array( $nvx_clinics['goya'] )
	? $nvx_clinics['goya']
	: array( 'whatsapp_href' => 'https://wa.me/34647505107' );

// header.php opens the canonical .nvx-brand-page wrapper unless the stored CMS
// body already owns the standard wrapper. In that latter case open a local
// wrapper here so the managed template remains structurally identical without
// ever delegating back to historical the_content().
$nvx_needs_local_wrapper = function_exists( 'nvx_page_has_standard_wrapper' )
	&& nvx_page_has_standard_wrapper();

get_header();

if ( $nvx_needs_local_wrapper ) :
	?>
	<div class="nvx-brand-page nvx-brand-page--gracias">
	<?php
endif;
?>
	<section class="nvx-brand-hero nvx-brand-hero--surface-ink" aria-labelledby="nvx-gracias-h1">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'SOLICITUD RECIBIDA', 'nuvanx-medical' ); ?></p>
				<h1 id="nvx-gracias-h1" class="nvx-brand-hero__title"><?php esc_html_e( 'Hemos recibido tu solicitud', 'nuvanx-medical' ); ?></h1>
				<p class="nvx-brand-hero__lead"><?php esc_html_e( 'Tu información ha llegado correctamente. El equipo de atención al paciente la revisará y te contactará para coordinar el siguiente paso. Si necesitas agilizar la cita, puedes escribir directamente a una de nuestras clínicas.', 'nuvanx-medical' ); ?></p>
				<div class="nvx-brand-actions" aria-label="<?php esc_attr_e( 'Canales directos de atención', 'nuvanx-medical' ); ?>">
					<a class="nvx-brand-btn nvx-btn--secondary-on-dark" href="<?php echo esc_url( (string) ( $nvx_chamberi['whatsapp_href'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer" data-gtag="click-whatsapp"><?php esc_html_e( 'WhatsApp Chamberí', 'nuvanx-medical' ); ?></a>
					<a class="nvx-brand-btn nvx-btn--secondary-on-dark" href="<?php echo esc_url( (string) ( $nvx_goya['whatsapp_href'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer" data-gtag="click-whatsapp"><?php esc_html_e( 'WhatsApp Salamanca–Goya', 'nuvanx-medical' ); ?></a>
				</div>
			</div>
		</div>
	</section>

	<section class="nvx-brand-section" aria-labelledby="nvx-gracias-next-title">
		<div class="nvx-brand-section__inner">
			<div class="nvx-brand-readable">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'SIGUIENTE PASO', 'nuvanx-medical' ); ?></p>
				<h2 id="nvx-gracias-next-title" class="nvx-brand-title"><?php esc_html_e( 'Qué ocurre ahora', 'nuvanx-medical' ); ?></h2>
				<ol class="nvx-brand-list">
					<li><strong><?php esc_html_e( 'Revisión de la solicitud.', 'nuvanx-medical' ); ?></strong> <?php esc_html_e( 'El equipo revisará el motivo de consulta y la información que hayas enviado.', 'nuvanx-medical' ); ?></li>
					<li><strong><?php esc_html_e( 'Contacto y coordinación.', 'nuvanx-medical' ); ?></strong> <?php esc_html_e( 'Te contactaremos para resolver dudas iniciales y proponerte fecha, sede y modalidad de valoración.', 'nuvanx-medical' ); ?></li>
					<li><strong><?php esc_html_e( 'Valoración médica.', 'nuvanx-medical' ); ?></strong> <?php esc_html_e( 'La indicación, el protocolo y el presupuesto definitivo se confirman tras la valoración médica correspondiente.', 'nuvanx-medical' ); ?></li>
				</ol>
				<p class="nvx-brand-body"><strong><?php esc_html_e( 'No necesitas volver a enviar el formulario.', 'nuvanx-medical' ); ?></strong> <?php esc_html_e( 'Si quieres añadir información antes de que te contactemos, utiliza uno de los canales directos indicados arriba.', 'nuvanx-medical' ); ?></p>
			</div>
		</div>
	</section>

	<section class="nvx-brand-section nvx-brand-section--soft" aria-labelledby="nvx-gracias-info-title">
		<div class="nvx-brand-section__inner">
			<div class="nvx-brand-readable">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'MIENTRAS TANTO', 'nuvanx-medical' ); ?></p>
				<h2 id="nvx-gracias-info-title" class="nvx-brand-title"><?php esc_html_e( 'Información útil antes de tu valoración', 'nuvanx-medical' ); ?></h2>
				<p class="nvx-brand-body"><?php esc_html_e( 'Puedes consultar nuestras soluciones médico-estéticas y las ubicaciones de NUVANX en Madrid. La información de la web es orientativa y no sustituye la valoración médica individual.', 'nuvanx-medical' ); ?></p>
				<div class="nvx-brand-actions">
					<a class="nvx-brand-btn nvx-btn--secondary" href="<?php echo esc_url( home_url( '/tratamientos/' ) ); ?>"><?php esc_html_e( 'Ver tratamientos', 'nuvanx-medical' ); ?></a>
					<a class="nvx-brand-btn nvx-btn--secondary" href="<?php echo esc_url( home_url( '/clinicas-de-medicina-estetica-nuvanx/' ) ); ?>"><?php esc_html_e( 'Ver clínicas', 'nuvanx-medical' ); ?></a>
				</div>
			</div>
		</div>
	</section>
<?php
if ( $nvx_needs_local_wrapper ) :
	?>
	</div><!-- .nvx-brand-page--gracias -->
	<?php
endif;

get_footer();
