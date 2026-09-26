<?php

namespace Signa\Elementor;

use Signa\Config\Settings;
use Signa\Front\FormRenderer;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

class FormWidget extends \Elementor\Widget_Base {
	private $renderer;
	private $settings;

	public function __construct( FormRenderer $renderer, Settings $settings, array $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	public function get_name(): string {
		return 'signa-form';
	}

	public function get_title(): string {
		return __( 'فرم ورود پیامکی سیگنا', 'signa' );
	}

	public function get_icon(): string {
		return 'eicon-lock-user';
	}

	public function get_categories(): array {
		return array( Module::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'otp', 'login', 'mobile', 'sms', 'signa' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'content',
			array( 'label' => __( 'محتوا', 'signa' ) )
		);

		$this->add_control( 'title', array( 'label' => __( 'عنوان', 'signa' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ) );
		$this->add_control( 'description', array( 'label' => __( 'توضیح', 'signa' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => '' ) );
		$this->add_control( 'redirect', array( 'label' => __( 'مقصد پس از ورود', 'signa' ), 'type' => \Elementor\Controls_Manager::URL, 'default' => array( 'url' => '' ) ) );

		$this->end_controls_section();

		$this->start_controls_section(
			'appearance',
			array( 'label' => __( 'ظاهر', 'signa' ) )
		);

		$this->add_control(
			'skin',
			array(
				'label'   => __( 'پوسته', 'signa' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''      => __( 'از تنظیمات افزونه', 'signa' ),
					'line'  => __( 'خطی', 'signa' ),
					'card'  => __( 'کارت', 'signa' ),
					'glass' => __( 'شیشه‌ای', 'signa' ),
					'slate' => __( 'تیره', 'signa' ),
					'pill'  => __( 'گرد', 'signa' ),
				),
			)
		);

		$this->add_control( 'accent', array( 'label' => __( 'رنگ تأکیدی', 'signa' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '' ) );
		$this->add_control( 'width', array( 'label' => __( 'عرض (پیکسل)', 'signa' ), 'type' => \Elementor\Controls_Manager::NUMBER, 'min' => 280, 'max' => 900, 'default' => '' ) );
		$this->add_control( 'radius', array( 'label' => __( 'گردی گوشه‌ها', 'signa' ), 'type' => \Elementor\Controls_Manager::NUMBER, 'min' => 0, 'max' => 40, 'default' => '' ) );

		$this->add_control(
			'align',
			array(
				'label'   => __( 'چینش', 'signa' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => __( 'از تنظیمات افزونه', 'signa' ),
					'center' => __( 'مرکز', 'signa' ),
					'start'  => __( 'ابتدا', 'signa' ),
					'end'    => __( 'انتها', 'signa' ),
				),
			)
		);

		$this->add_control(
			'code_input',
			array(
				'label'   => __( 'ورودی کد', 'signa' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => __( 'از تنظیمات افزونه', 'signa' ),
					'boxes'  => __( 'خانه‌های جداگانه', 'signa' ),
					'single' => __( 'یک کادر', 'signa' ),
				),
			)
		);

		$this->add_control(
			'show_brand',
			array(
				'label'        => __( 'نمایش لوگو', 'signa' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => '1',
			)
		);

		$this->add_control( 'logo', array( 'label' => __( 'لوگو', 'signa' ), 'type' => \Elementor\Controls_Manager::MEDIA, 'default' => array( 'url' => '' ) ) );
		$this->add_control( 'custom_class', array( 'label' => __( 'کلاس CSS', 'signa' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ) );

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

		echo $this->renderer->render( $atts );
	}

	protected function content_template(): void {
	}
}
