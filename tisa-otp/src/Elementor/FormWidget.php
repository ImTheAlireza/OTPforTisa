<?php
/**
 * Elementor widget wrapping the sign-in form.
 *
 * Only loaded when Elementor itself is present.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Elementor;

use TisaOtp\Config\Settings;
use TisaOtp\Front\FormRenderer;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

class FormWidget extends \Elementor\Widget_Base {

	/** @var FormRenderer */
	private $renderer;

	/** @var Settings */
	private $settings;

	public function __construct( FormRenderer $renderer, Settings $settings, array $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	public function get_name(): string {
		return 'tisa-otp-form';
	}

	public function get_title(): string {
		return __( 'فرم ورود پیامکی تیسا', 'tisa-otp' );
	}

	public function get_icon(): string {
		return 'eicon-lock-user';
	}

	public function get_categories(): array {
		return array( Module::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'otp', 'login', 'mobile', 'sms', 'tisa' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'content',
			array( 'label' => __( 'محتوا', 'tisa-otp' ) )
		);

		$this->add_control( 'title', array( 'label' => __( 'عنوان', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ) );
		$this->add_control( 'description', array( 'label' => __( 'توضیح', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => '' ) );
		$this->add_control( 'redirect', array( 'label' => __( 'مقصد پس از ورود', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::URL, 'default' => array( 'url' => '' ) ) );

		$this->end_controls_section();

		$this->start_controls_section(
			'appearance',
			array( 'label' => __( 'ظاهر', 'tisa-otp' ) )
		);

		$this->add_control(
			'skin',
			array(
				'label'   => __( 'پوسته', 'tisa-otp' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''      => __( 'از تنظیمات افزونه', 'tisa-otp' ),
					'line'  => __( 'خطی', 'tisa-otp' ),
					'card'  => __( 'کارت', 'tisa-otp' ),
					'glass' => __( 'شیشه‌ای', 'tisa-otp' ),
					'slate' => __( 'تیره', 'tisa-otp' ),
					'pill'  => __( 'گرد', 'tisa-otp' ),
				),
			)
		);

		$this->add_control( 'accent', array( 'label' => __( 'رنگ تأکیدی', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '' ) );
		$this->add_control( 'width', array( 'label' => __( 'عرض (پیکسل)', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::NUMBER, 'min' => 280, 'max' => 900, 'default' => '' ) );
		$this->add_control( 'radius', array( 'label' => __( 'گردی گوشه‌ها', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::NUMBER, 'min' => 0, 'max' => 40, 'default' => '' ) );

		$this->add_control(
			'align',
			array(
				'label'   => __( 'چینش', 'tisa-otp' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => __( 'از تنظیمات افزونه', 'tisa-otp' ),
					'center' => __( 'مرکز', 'tisa-otp' ),
					'start'  => __( 'ابتدا', 'tisa-otp' ),
					'end'    => __( 'انتها', 'tisa-otp' ),
				),
			)
		);

		$this->add_control(
			'code_input',
			array(
				'label'   => __( 'ورودی کد', 'tisa-otp' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => __( 'از تنظیمات افزونه', 'tisa-otp' ),
					'boxes'  => __( 'خانه‌های جداگانه', 'tisa-otp' ),
					'single' => __( 'یک کادر', 'tisa-otp' ),
				),
			)
		);

		$this->add_control(
			'show_brand',
			array(
				'label'        => __( 'نمایش لوگو', 'tisa-otp' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => '1',
			)
		);

		$this->add_control( 'logo', array( 'label' => __( 'لوگو', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::MEDIA, 'default' => array( 'url' => '' ) ) );
		$this->add_control( 'custom_class', array( 'label' => __( 'کلاس CSS', 'tisa-otp' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ) );

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$atts = array(
			'title'        => isset( $settings['title'] ) ? $settings['title'] : '',
			'description'  => isset( $settings['description'] ) ? $settings['description'] : '',
			'skin'         => isset( $settings['skin'] ) ? $settings['skin'] : '',
			'accent'       => isset( $settings['accent'] ) ? $settings['accent'] : '',
			'width'        => isset( $settings['width'] ) ? $settings['width'] : '',
			'radius'       => isset( $settings['radius'] ) ? $settings['radius'] : '',
			'align'        => isset( $settings['align'] ) ? $settings['align'] : '',
			'code_input'   => isset( $settings['code_input'] ) ? $settings['code_input'] : '',
			'show_brand'   => isset( $settings['show_brand'] ) ? $settings['show_brand'] : '',
			'logo'         => isset( $settings['logo']['url'] ) ? $settings['logo']['url'] : '',
			'custom_class' => isset( $settings['custom_class'] ) ? $settings['custom_class'] : '',
		);

		if ( ! empty( $settings['redirect']['url'] ) ) {
			$atts['redirect'] = $settings['redirect']['url'];
		}

		echo $this->renderer->render( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	protected function content_template(): void {
		// Rendered server-side only.
	}
}
