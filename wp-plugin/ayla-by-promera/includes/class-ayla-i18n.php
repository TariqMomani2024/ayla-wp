<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Arabic is the default; English is used when the admin user's locale is English. */
class Ayla_I18n {

	public static function lang() {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();

		return 0 === strpos( (string) $locale, 'en' ) ? 'en' : 'ar';
	}

	public static function is_rtl() {
		return 'ar' === self::lang();
	}

	public static function t( $key ) {
		$all  = self::all();
		$lang = self::lang();

		if ( isset( $all[ $key ][ $lang ] ) ) {
			return $all[ $key ][ $lang ];
		}

		return isset( $all[ $key ]['en'] ) ? $all[ $key ]['en'] : $key;
	}

	/** @return array<string,string> strings for the current language, for the JS app */
	public static function for_js() {
		$out = array();
		foreach ( array_keys( self::all() ) as $key ) {
			$out[ $key ] = self::t( $key );
		}

		return $out;
	}

	private static function all() {
		return array(
			'brand'               => array( 'en' => 'Ayla by Promera', 'ar' => 'Ayla by Promera' ),
			'menu'                => array( 'en' => 'Ayla', 'ar' => 'Ayla' ),
			'settings'            => array( 'en' => 'Settings', 'ar' => 'الإعدادات' ),
			'dashboard'           => array( 'en' => 'Dashboard', 'ar' => 'لوحة التحكم' ),
			'not_configured'      => array( 'en' => 'Ayla is not connected yet. Open Settings and enter the connection details you received from Promera.', 'ar' => 'لم يتم ربط Ayla بعد. افتح الإعدادات وأدخل بيانات الاتصال التي وصلتك من Promera.' ),
			'connection_details'  => array( 'en' => 'Connection details', 'ar' => 'بيانات الاتصال' ),
			'domain'              => array( 'en' => 'Domain', 'ar' => 'الدومين' ),
			'username'            => array( 'en' => 'Username', 'ar' => 'اسم المستخدم' ),
			'password'            => array( 'en' => 'Password', 'ar' => 'كلمة المرور' ),
			'api_key'             => array( 'en' => 'API key', 'ar' => 'مفتاح API' ),
			'keep_saved'          => array( 'en' => 'Saved. Leave blank to keep it.', 'ar' => 'محفوظ. اتركه فارغاً للإبقاء عليه.' ),
			'save'                => array( 'en' => 'Save', 'ar' => 'حفظ' ),
			'saved'               => array( 'en' => 'Settings saved.', 'ar' => 'تم حفظ الإعدادات.' ),
			'webhook_url'         => array( 'en' => 'Webhook URL for Meta', 'ar' => 'رابط Webhook لميتا' ),
			'connection_status'   => array( 'en' => 'Connection status', 'ar' => 'حالة الاتصال' ),
			'connected'           => array( 'en' => 'Connected', 'ar' => 'متصل' ),
			'checking'            => array( 'en' => 'Checking...', 'ar' => 'جارٍ الفحص...' ),
			'error_generic'       => array( 'en' => 'Ayla could not complete the request. Please try again later.', 'ar' => 'تعذر على Ayla إكمال الطلب. يرجى المحاولة لاحقاً.' ),
			'forbidden'           => array( 'en' => 'You do not have permission to do this.', 'ar' => 'ليست لديك صلاحية لهذا الإجراء.' ),
			'tab_overview'        => array( 'en' => 'Overview', 'ar' => 'نظرة عامة' ),
			'tab_leads'           => array( 'en' => 'Prospects', 'ar' => 'العملاء المحتملون' ),
			'tab_appointments'    => array( 'en' => 'Appointments', 'ar' => 'المواعيد' ),
			'tab_conversations'   => array( 'en' => 'Conversations', 'ar' => 'المحادثات' ),
			'tab_doctors'         => array( 'en' => 'Doctors', 'ar' => 'الأطباء' ),
			'tab_business'        => array( 'en' => 'Business info', 'ar' => 'بيانات النشاط' ),
			'loading'             => array( 'en' => 'Loading...', 'ar' => 'جارٍ التحميل...' ),
			'empty'               => array( 'en' => 'Nothing to show yet.', 'ar' => 'لا توجد بيانات بعد.' ),
			'period_30'           => array( 'en' => 'Last 30 days', 'ar' => 'آخر 30 يوماً' ),
			'k_conversations'     => array( 'en' => 'Conversations', 'ar' => 'المحادثات' ),
			'k_needs_human'       => array( 'en' => 'Need a human', 'ar' => 'تحتاج موظفاً' ),
			'k_messages_in'       => array( 'en' => 'Messages received', 'ar' => 'الرسائل الواردة' ),
			'k_messages_out'      => array( 'en' => 'Messages sent', 'ar' => 'الرسائل المرسلة' ),
			'k_new_leads'         => array( 'en' => 'New prospects', 'ar' => 'عملاء محتملون جدد' ),
			'k_hot_leads'         => array( 'en' => 'Hot prospects', 'ar' => 'عملاء ذوو اهتمام عالٍ' ),
			'k_conversion'        => array( 'en' => 'Booking rate', 'ar' => 'نسبة الحجز' ),
			'k_upcoming'          => array( 'en' => 'Upcoming appointments', 'ar' => 'مواعيد قادمة' ),
			'k_no_shows'          => array( 'en' => 'No-shows', 'ar' => 'عدم الحضور' ),
			'k_reminders'         => array( 'en' => 'Reminders sent', 'ar' => 'تذكيرات مرسلة' ),
			'k_response'          => array( 'en' => 'Avg. response (sec)', 'ar' => 'متوسط الرد (ثانية)' ),
			'by_doctor'           => array( 'en' => 'Appointments by doctor', 'ar' => 'المواعيد حسب الطبيب' ),
			'busiest_hours'       => array( 'en' => 'Busiest hours', 'ar' => 'أوقات الذروة' ),
			'name'                => array( 'en' => 'Name', 'ar' => 'الاسم' ),
			'phone'               => array( 'en' => 'Phone', 'ar' => 'الهاتف' ),
			'score'               => array( 'en' => 'Score', 'ar' => 'التقييم' ),
			'status'              => array( 'en' => 'Status', 'ar' => 'الحالة' ),
			'last_message'        => array( 'en' => 'Last message', 'ar' => 'آخر رسالة' ),
			'patient'             => array( 'en' => 'Patient', 'ar' => 'المريض' ),
			'doctor'              => array( 'en' => 'Doctor', 'ar' => 'الطبيب' ),
			'service'             => array( 'en' => 'Service', 'ar' => 'الخدمة' ),
			'date'                => array( 'en' => 'Date', 'ar' => 'التاريخ' ),
			'time'                => array( 'en' => 'Time', 'ar' => 'الوقت' ),
			'open'                => array( 'en' => 'Open', 'ar' => 'فتح' ),
			'back'                => array( 'en' => 'Back', 'ar' => 'رجوع' ),
			'needs_human'         => array( 'en' => 'Needs a human', 'ar' => 'تحتاج موظفاً' ),
			'resume_ayla'         => array( 'en' => 'Mark as handled / Resume Ayla', 'ar' => 'تمت المعالجة / إعادة تفعيل Ayla' ),
			'doctors_count'       => array( 'en' => 'Doctors', 'ar' => 'الأطباء' ),
			'of'                  => array( 'en' => 'of', 'ar' => 'من' ),
			'add_doctor'          => array( 'en' => 'Add doctor', 'ar' => 'إضافة طبيب' ),
			'edit'                => array( 'en' => 'Edit', 'ar' => 'تعديل' ),
			'remove'              => array( 'en' => 'Remove', 'ar' => 'حذف' ),
			'confirm_remove'      => array( 'en' => 'Remove this item?', 'ar' => 'هل تريد حذف هذا العنصر؟' ),
			'specialty'           => array( 'en' => 'Specialty', 'ar' => 'التخصص' ),
			'duration_min'        => array( 'en' => 'Appointment length (min)', 'ar' => 'مدة الموعد (دقيقة)' ),
			'buffer_min'          => array( 'en' => 'Buffer between appointments (min)', 'ar' => 'فاصل بين المواعيد (دقيقة)' ),
			'active'              => array( 'en' => 'Active', 'ar' => 'فعّال' ),
			'working_hours'       => array( 'en' => 'Working hours', 'ar' => 'ساعات العمل' ),
			'from'                => array( 'en' => 'From', 'ar' => 'من' ),
			'to'                  => array( 'en' => 'To', 'ar' => 'إلى' ),
			'cancel'              => array( 'en' => 'Cancel', 'ar' => 'إلغاء' ),
			'clinic_info'         => array( 'en' => 'Clinic info', 'ar' => 'معلومات العيادة' ),
			'address'             => array( 'en' => 'Address', 'ar' => 'العنوان' ),
			'email'               => array( 'en' => 'Email', 'ar' => 'البريد الإلكتروني' ),
			'map_url'             => array( 'en' => 'Map link', 'ar' => 'رابط الخريطة' ),
			'about'               => array( 'en' => 'About', 'ar' => 'نبذة' ),
			'staff_number'        => array( 'en' => 'Staff alert WhatsApp number', 'ar' => 'رقم واتساب لتنبيه الموظفين' ),
			'emergency_contact'   => array( 'en' => 'Emergency contact shown to patients', 'ar' => 'جهة الاتصال للطوارئ التي تظهر للمرضى' ),
			'days_off'            => array( 'en' => 'Days off (one date per line, YYYY-MM-DD)', 'ar' => 'أيام الإجازة (تاريخ في كل سطر، YYYY-MM-DD)' ),
			'services_prices'     => array( 'en' => 'Services and prices', 'ar' => 'الخدمات والأسعار' ),
			'price'               => array( 'en' => 'Price', 'ar' => 'السعر' ),
			'currency'            => array( 'en' => 'Currency', 'ar' => 'العملة' ),
			'add_service'         => array( 'en' => 'Add service', 'ar' => 'إضافة خدمة' ),
			'prices_locked'       => array( 'en' => 'Services and prices are managed by Promera for your account.', 'ar' => 'الخدمات والأسعار تُدار من Promera لحسابك.' ),
			'faqs'                => array( 'en' => 'Frequently asked questions', 'ar' => 'الأسئلة الشائعة' ),
			'question'            => array( 'en' => 'Question', 'ar' => 'السؤال' ),
			'answer'              => array( 'en' => 'Answer', 'ar' => 'الجواب' ),
			'add_faq'             => array( 'en' => 'Add question', 'ar' => 'إضافة سؤال' ),
			'subscription'        => array( 'en' => 'Subscription', 'ar' => 'الاشتراك' ),
			'expires_on'          => array( 'en' => 'Valid until', 'ar' => 'صالح حتى' ),
			'days_left'           => array( 'en' => 'days left', 'ar' => 'يوم متبقٍ' ),
			'days'                => array( 'en' => 'Days', 'ar' => 'الأيام' ),
			'mon'                 => array( 'en' => 'Monday', 'ar' => 'الاثنين' ),
			'tue'                 => array( 'en' => 'Tuesday', 'ar' => 'الثلاثاء' ),
			'wed'                 => array( 'en' => 'Wednesday', 'ar' => 'الأربعاء' ),
			'thu'                 => array( 'en' => 'Thursday', 'ar' => 'الخميس' ),
			'fri'                 => array( 'en' => 'Friday', 'ar' => 'الجمعة' ),
			'sat'                 => array( 'en' => 'Saturday', 'ar' => 'السبت' ),
			'sun'                 => array( 'en' => 'Sunday', 'ar' => 'الأحد' ),
			'next'                => array( 'en' => 'Next', 'ar' => 'التالي' ),
			'prev'                => array( 'en' => 'Previous', 'ar' => 'السابق' ),
			'filter_all'          => array( 'en' => 'All', 'ar' => 'الكل' ),
		);
	}
}
