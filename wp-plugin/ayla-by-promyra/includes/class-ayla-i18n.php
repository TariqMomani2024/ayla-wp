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
			'brand'               => array( 'en' => 'Ayla by Promyra Digital Solutions', 'ar' => 'Ayla by Promyra Digital Solutions' ),
			'menu'                => array( 'en' => 'Ayla', 'ar' => 'Ayla' ),
			'settings'            => array( 'en' => 'Settings', 'ar' => 'الإعدادات' ),
			'dashboard'           => array( 'en' => 'Dashboard', 'ar' => 'لوحة التحكم' ),
			'not_configured'      => array( 'en' => 'Ayla is not connected yet. Open Settings and enter the connection details you received from Promyra Digital Solutions.', 'ar' => 'لم يتم ربط Ayla بعد. افتح الإعدادات وأدخل بيانات الاتصال التي وصلتك من Promyra Digital Solutions.' ),
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
			'login_failed'        => array( 'en' => 'Login to Ayla failed. Check the username and password in Ayla Settings.', 'ar' => 'فشل تسجيل الدخول إلى Ayla. تحقق من اسم المستخدم وكلمة المرور في الإعدادات.' ),
			'api_key_rejected'    => array( 'en' => 'Ayla rejected the API key or domain. Check both in Ayla Settings.', 'ar' => 'رفضت Ayla مفتاح API أو النطاق. تحقق منهما في الإعدادات.' ),
			'rate_limited'        => array( 'en' => 'Too many login attempts. Wait one minute and try again.', 'ar' => 'محاولات تسجيل دخول كثيرة. انتظر دقيقة ثم حاول مجدداً.' ),
			'password_special_chars' => array( 'en' => 'Special characters are supported.', 'ar' => 'الرموز الخاصة مدعومة.' ),
			'forbidden'           => array( 'en' => 'You do not have permission to do this.', 'ar' => 'ليست لديك صلاحية لهذا الإجراء.' ),
			'hide_chat'           => array( 'en' => 'Hide the Ayla chat on this page', 'ar' => 'إخفاء محادثة Ayla في هذه الصفحة' ),
			'hide_chat_help'      => array( 'en' => 'The chat shows on every page unless you tick this.', 'ar' => 'تظهر المحادثة في كل الصفحات ما لم تحدد هذا الخيار.' ),
			'site_learning'       => array( 'en' => 'Learning from the website', 'ar' => 'التعلّم من الموقع' ),
			'site_learning_help'  => array( 'en' => 'Ayla reads this website\'s public content: business details, services, prices, offers, FAQ, pages and blog posts, and uses it to answer customers. Changes are sent automatically a few minutes after you save them.', 'ar' => 'تقرأ Ayla المحتوى العام لهذا الموقع: بيانات النشاط والخدمات والأسعار والعروض والأسئلة الشائعة والصفحات والمقالات، وتعتمد عليه في إجاباتها. تُرسل التعديلات تلقائياً بعد دقائق من حفظها.' ),
			'site_sync_now'       => array( 'en' => 'Read the website now', 'ar' => 'قراءة الموقع الآن' ),
			'site_last_sync'      => array( 'en' => 'Last sync', 'ar' => 'آخر مزامنة' ),
			'site_never'          => array( 'en' => 'Not synced yet', 'ar' => 'لم تتم المزامنة بعد' ),
			'site_sync_ok'        => array( 'en' => 'The website was read successfully.', 'ar' => 'تمت قراءة الموقع بنجاح.' ),
			'site_sync_failed'    => array( 'en' => 'The website could not be sent to Ayla.', 'ar' => 'تعذر إرسال بيانات الموقع إلى Ayla.' ),
			'site_counts'         => array( 'en' => 'Branches / services / specialists / FAQ / articles', 'ar' => 'الفروع / الخدمات / المتخصصون / الأسئلة / المقالات' ),
			'site_over_limit'     => array( 'en' => '%d specialist(s) were not activated because they exceed the limit of your Ayla plan. Contact Promyra Digital Solutions to raise it.', 'ar' => 'لم يتم تفعيل %d من المتخصصين لأنهم يتجاوزون حد الباقة في Ayla. تواصل مع Promyra Digital Solutions لرفع الحد.' ),
			'tab_overview'        => array( 'en' => 'Overview', 'ar' => 'نظرة عامة' ),
			'tab_leads'           => array( 'en' => 'Prospects', 'ar' => 'العملاء المحتملون' ),
			'tab_appointments'    => array( 'en' => 'Appointments', 'ar' => 'المواعيد' ),
			'tab_conversations'   => array( 'en' => 'Conversations', 'ar' => 'المحادثات' ),
			'tab_doctors'         => array( 'en' => 'Specialists', 'ar' => 'المتخصصون' ),
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
			'by_doctor'           => array( 'en' => 'Appointments by specialist', 'ar' => 'المواعيد حسب المتخصص' ),
			'busiest_hours'       => array( 'en' => 'Busiest hours', 'ar' => 'أوقات الذروة' ),
			'name'                => array( 'en' => 'Name', 'ar' => 'الاسم' ),
			'phone'               => array( 'en' => 'Phone', 'ar' => 'الهاتف' ),
			'score'               => array( 'en' => 'Score', 'ar' => 'التقييم' ),
			'status'              => array( 'en' => 'Status', 'ar' => 'الحالة' ),
			'last_message'        => array( 'en' => 'Last message', 'ar' => 'آخر رسالة' ),
			'patient'             => array( 'en' => 'Customer', 'ar' => 'العميل' ),
			'doctor'              => array( 'en' => 'Specialist', 'ar' => 'المتخصص' ),
			'service'             => array( 'en' => 'Service', 'ar' => 'الخدمة' ),
			'date'                => array( 'en' => 'Date', 'ar' => 'التاريخ' ),
			'time'                => array( 'en' => 'Time', 'ar' => 'الوقت' ),
			'open'                => array( 'en' => 'Open', 'ar' => 'فتح' ),
			'back'                => array( 'en' => 'Back', 'ar' => 'رجوع' ),
			'needs_human'         => array( 'en' => 'Needs a human', 'ar' => 'تحتاج موظفاً' ),
			'resume_ayla'         => array( 'en' => 'Mark as handled / Resume Ayla', 'ar' => 'تمت المعالجة / إعادة تفعيل Ayla' ),
			'doctors_count'       => array( 'en' => 'Specialists', 'ar' => 'المتخصصون' ),
			'of'                  => array( 'en' => 'of', 'ar' => 'من' ),
			'add_doctor'          => array( 'en' => 'Add specialist', 'ar' => 'إضافة متخصص' ),
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
			'clinic_info'         => array( 'en' => 'Business details', 'ar' => 'تفاصيل النشاط' ),
			'address'             => array( 'en' => 'Address', 'ar' => 'العنوان' ),
			'email'               => array( 'en' => 'Email', 'ar' => 'البريد الإلكتروني' ),
			'map_url'             => array( 'en' => 'Map link', 'ar' => 'رابط الخريطة' ),
			'about'               => array( 'en' => 'About', 'ar' => 'نبذة' ),
			'staff_number'        => array( 'en' => 'Staff alert WhatsApp number', 'ar' => 'رقم واتساب لتنبيه الموظفين' ),
			'emergency_contact'   => array( 'en' => 'Emergency contact shown to customers', 'ar' => 'جهة اتصال الطوارئ التي تظهر للعملاء' ),
			'days_off'            => array( 'en' => 'Days off (one date per line, YYYY-MM-DD)', 'ar' => 'أيام الإجازة (تاريخ في كل سطر، YYYY-MM-DD)' ),
			'services_prices'     => array( 'en' => 'Services and prices', 'ar' => 'الخدمات والأسعار' ),
			'price'               => array( 'en' => 'Price', 'ar' => 'السعر' ),
			'currency'            => array( 'en' => 'Currency', 'ar' => 'العملة' ),
			'add_service'         => array( 'en' => 'Add service', 'ar' => 'إضافة خدمة' ),
			'prices_locked'       => array( 'en' => 'Services and prices are managed by Promyra Digital Solutions for your account.', 'ar' => 'الخدمات والأسعار تُدار من Promyra Digital Solutions لحسابك.' ),
			'faqs'                => array( 'en' => 'Frequently asked questions', 'ar' => 'الأسئلة الشائعة' ),
			'question'            => array( 'en' => 'Question', 'ar' => 'السؤال' ),
			'answer'              => array( 'en' => 'Answer', 'ar' => 'الجواب' ),
			'add_faq'             => array( 'en' => 'Add question', 'ar' => 'إضافة سؤال' ),
			'fill_from_site'      => array( 'en' => 'Fill from my website', 'ar' => 'تعبئة من موقعي' ),
			'scanning'            => array( 'en' => 'Reading your website...', 'ar' => 'جارٍ قراءة موقعك...' ),
			'filled_review'       => array( 'en' => 'Filled from your website: %s. Empty fields only. Review them, then press Save.', 'ar' => 'تمت التعبئة من موقعك: %s. الحقول الفارغة فقط. راجعها ثم اضغط حفظ.' ),
			'nothing_to_fill'     => array( 'en' => 'Nothing new was found on your website to fill in.', 'ar' => 'لم يُعثر على بيانات جديدة في موقعك لتعبئتها.' ),
			'copy_to_all'         => array( 'en' => 'Copy to all days', 'ar' => 'نسخ لكل الأيام' ),
			'copy_to_all_help'    => array( 'en' => 'Copy this day to every other day', 'ar' => 'انسخ هذا اليوم إلى بقية الأيام' ),
			'second_period'       => array( 'en' => '+ Second period', 'ar' => '+ فترة ثانية' ),
			'second_period_help'  => array( 'en' => 'Only if you close in the middle of the day (for example a break)', 'ar' => 'فقط إذا كان هناك إغلاق في منتصف اليوم (مثل استراحة)' ),
			'clear_day'           => array( 'en' => 'Clear', 'ar' => 'مسح' ),
			'closed_hint'         => array( 'en' => 'Leave a day empty if you are closed that day.', 'ar' => 'اترك اليوم فارغاً إذا كنتم مغلقين فيه.' ),
			'hours_incomplete'    => array( 'en' => 'Working hours: enter both the opening and the closing time for', 'ar' => 'ساعات العمل: أدخل وقت الفتح ووقت الإغلاق معاً ليوم' ),
			'hours_order'         => array( 'en' => 'Working hours: the closing time must be after the opening time for', 'ar' => 'ساعات العمل: يجب أن يكون الإغلاق بعد الفتح في يوم' ),
			'hours_overlap'       => array( 'en' => 'Working hours: the second period must start after the first one ends for', 'ar' => 'ساعات العمل: يجب أن تبدأ الفترة الثانية بعد انتهاء الأولى في يوم' ),
			'import_faqs'         => array( 'en' => 'Import questions from my website', 'ar' => 'استيراد الأسئلة من موقعي' ),
			'faqs_found'          => array( 'en' => 'Questions found on your website', 'ar' => 'أسئلة وُجدت في موقعك' ),
			'faqs_none'           => array( 'en' => 'No FAQ was found on your website. It looks for FAQ schema, FAQ post types, and the FAQ blocks of Yoast and Rank Math.', 'ar' => 'لم يُعثر على أسئلة شائعة في موقعك. يبحث عن بيانات FAQ المضمّنة وأنواع منشورات الأسئلة وكتل الأسئلة في Yoast وRank Math.' ),
			'faqs_all_exist'      => array( 'en' => 'All the questions on your website are already here.', 'ar' => 'كل الأسئلة الموجودة في موقعك مضافة هنا مسبقاً.' ),
			'import_selected'     => array( 'en' => 'Import selected', 'ar' => 'استيراد المحدد' ),
			'faqs_imported'       => array( 'en' => 'Questions imported', 'ar' => 'تم استيراد الأسئلة' ),
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
