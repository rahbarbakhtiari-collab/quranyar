<?php
/**
 * University-scoped Excel / CSV personalized messages.
 * The uploaded dataset is read twice (header preview and final POST), never saved
 * to Media Library or options. Only server-validated aggregate import counts and
 * the necessary final per-recipient message payloads enter existing tables.
 */
if (!defined('ABSPATH')) exit;

final class QYS_Excel {
    const MAX_SIZE = 5242880;
    const MAX_ROWS = 5000;
    const MAX_COLUMNS = 80;
    const MAX_HEADER_CHARS = 120;
    const MAX_CELL_CHARS = 4000;

    public static function init($plugin_file) {
        add_action('wp_ajax_qys_excel_headers', [__CLASS__, 'preview_headers']);
        add_action('admin_post_qys_excel_create', [__CLASS__, 'create_post']);
        add_action('admin_post_qys_excel_followup_export', [__CLASS__, 'export_followup']);
        add_action('wp_enqueue_scripts', static function() use ($plugin_file) {
            if (!class_exists('QYS_Portal_Asset_Policy',false) || !QYS_Portal_Asset_Policy::should_load('excel')) return;
            wp_enqueue_style('qys-excel', plugins_url('assets/qys-excel.css', $plugin_file), ['qys-university-portal'], QYS_Config::VERSION);
            wp_enqueue_script('qys-excel', plugins_url('assets/qys-excel.js', $plugin_file), ['qys-university-portal'], QYS_Config::VERSION, true);
            wp_localize_script('qys-excel', 'QYSExcel', [
                'url'=>admin_url('admin-ajax.php'),
                'nonce'=>wp_create_nonce('qys_excel_headers'),
                'university'=>QYS_Tenant_Context::campus(),
                'maxSize'=>self::MAX_SIZE,
            ]);
        });
    }

    /** Reject files that would expand beyond reasonable resource limits. */
    public static function check_file($file, $must_be_upload=true) {
        if (!is_array($file) || !isset($file['error'],$file['size'],$file['name'],$file['tmp_name'])
            || (int)$file['error']!==UPLOAD_ERR_OK || (int)$file['size']<=0 || (int)$file['size']>self::MAX_SIZE
            || !is_string($file['name']) || !is_string($file['tmp_name'])) {
            throw new RuntimeException('فایل CSV یا XLSX معتبر با حجم حداکثر پنج مگابایت انتخاب کنید.');
        }
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        if (!in_array($ext,['csv','xlsx'],true)) throw new RuntimeException('فقط فایل CSV یا XLSX پذیرفته می‌شود.');
        if ($must_be_upload && !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('بارگذاری فایل تأیید نشد.');
        if (!is_readable($file['tmp_name'])) throw new RuntimeException('خواندن فایل امکان‌پذیر نیست.');
        $sample=file_get_contents($file['tmp_name'],false,null,0,8192);
        if($sample===false)throw new RuntimeException('خواندن فایل امکان‌پذیر نیست.');
        if($ext==='csv' && str_contains($sample,"\0"))throw new RuntimeException('CSV متنی معتبر نیست.');
        if($ext==='xlsx' && !str_starts_with($sample,'PK'))throw new RuntimeException('امضای فایل XLSX معتبر نیست.');
        unset($sample);
        if ($ext==='xlsx') {
            if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string'))throw new RuntimeException('امکان خواندن XLSX روی سرور فعال نیست؛ CSV UTF-8 بارگذاری کنید.');
            $zip=new ZipArchive();
            if ($zip->open($file['tmp_name'])!==true)throw new RuntimeException('ساختار فایل XLSX معتبر نیست.');
            try {
                foreach(['xl/sharedStrings.xml'=>8388608,'xl/worksheets/sheet1.xml'=>15728640] as $name=>$limit) {
                    $stat=$zip->statName($name);
                    if($name==='xl/worksheets/sheet1.xml'&&!$stat)throw new RuntimeException('برگه نخست فایل اکسل یافت نشد.');
                    if($stat && ((int)$stat['size']>$limit || (int)$stat['size']<0))throw new RuntimeException('فایل اکسل بیش‌ازحد بزرگ یا فشرده است.');
                }
            } finally {$zip->close();}
        }
        return $ext;
    }

    /** Keep positional data instead of keying by header: detect duplicate header names. */
    public static function read_dataset($path,$extension) {
        if($extension==='csv'){
            $all=self::csv_data($path);
        } elseif($extension==='xlsx') {
            $all=self::xlsx_data($path);
        } else throw new RuntimeException('پسوند فایل پشتیبانی نمی‌شود.');
        [$headers,$rows,$malformed]=$all;
        if(!$headers)return ['headers'=>[],'rows'=>[],'malformed'=>$malformed];
        if(count($headers)>self::MAX_COLUMNS)throw new RuntimeException('حداکثر ۸۰ ستون در یک فایل پشتیبانی می‌شود.');
        $seen=[];
        foreach($headers as $header){
            if(!is_string($header)||trim($header)===''||!preg_match('//u',$header)||QYS_Text_Normalizer::chars($header)>self::MAX_HEADER_CHARS)throw new RuntimeException('تمام ستون‌های فایل باید سربرگ معتبر، غیرخالی و حداکثر ۱۲۰ نویسه داشته باشند.');
            $normalized=QYS_Text_Normalizer::header($header);
            if($normalized===''||isset($seen[$normalized]))throw new RuntimeException('نام ستون‌ها تکراری یا پس از نرمال‌سازی خالی است. نام‌ها را قبل از بارگذاری اصلاح کنید.');
            $seen[$normalized]=true;
        }
        foreach($rows as $row)foreach($row as $cell){
            if(!is_scalar($cell)||!preg_match('//u',(string)$cell)||QYS_Text_Normalizer::chars((string)$cell)>self::MAX_CELL_CHARS)throw new RuntimeException('یکی از سلول‌های فایل نامعتبر یا بیش از ۴۰۰۰ نویسه است.');
        }
        if(count($rows)>self::MAX_ROWS)throw new RuntimeException('حداکثر ۵۰۰۰ ردیف اطلاعات پذیرفته می‌شود.');
        return ['headers'=>$headers,'rows'=>$rows,'malformed'=>$malformed];
    }

    private static function csv_data($path){
        $handle=fopen($path,'rb');
        if(!$handle)throw new RuntimeException('خواندن فایل CSV امکان‌پذیر نیست.');
        try{
            $first=(string)fgets($handle);rewind($handle);
            $delimiter=substr_count($first,';')>substr_count($first,',')?';':',';
            $headers=fgetcsv($handle,0,$delimiter,'"','');
            if(!$headers)return [[],[],0];
            $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
            $rows=[];$malformed=0;
            while(($cells=fgetcsv($handle,0,$delimiter,'"',''))!==false){
                if(count($cells)!==count($headers))$malformed++;
                else $rows[]=$cells;
                if(count($rows)+$malformed>self::MAX_ROWS)throw new RuntimeException('فایل بیشتر از ۵۰۰۰ ردیف دارد.');
            }
            return [$headers,$rows,$malformed];
        }finally{fclose($handle);}
    }

    /** Minimal bounded XLSX first-sheet reader. Never evaluate Excel formulae. */
    private static function xlsx_data($path){
        if(!class_exists('ZipArchive')||!function_exists('simplexml_load_string'))throw new RuntimeException('XLSX در این سرور پشتیبانی نمی‌شود؛ CSV استفاده کنید.');
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('فایل اکسل معتبر نیست.');
        try {
            $str=$zip->getFromName('xl/sharedStrings.xml');
            $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        } finally {$zip->close();}
        if($xml===false)throw new RuntimeException('برگه نخست فایل اکسل پیدا نشد.');
        $shared=[];
        if(($str!==false && preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i',$str)) || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i',$xml))throw new RuntimeException('ساختار XML ناامن در فایل اکسل پذیرفته نمی‌شود.');
        $old_errors=libxml_use_internal_errors(true);
        libxml_clear_errors();
        if($str!==false){
            $shared_xml=simplexml_load_string($str,'SimpleXMLElement',LIBXML_NONET|LIBXML_COMPACT);
            if(!$shared_xml){libxml_clear_errors();libxml_use_internal_errors($old_errors);throw new RuntimeException('خواندن رشته‌های اکسل ناموفق بود.');}
            foreach($shared_xml->si as $si){
                $parts=$si->xpath('.//*[local-name()="t"]');$value='';
                foreach((array)$parts as $part)$value.=(string)$part;
                $shared[]=$value;
            }
        }
        $sheet=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET|LIBXML_COMPACT);
        if(!$sheet){libxml_clear_errors();libxml_use_internal_errors($old_errors);throw new RuntimeException('خواندن برگه اکسل ناموفق بود.');}
        libxml_clear_errors();libxml_use_internal_errors($old_errors);
        $all=[];
        foreach($sheet->sheetData->row as $row){
            $values=[];
            foreach($row->c as $cell){
                $ref=(string)$cell['r'];
                if(!preg_match('/\A([A-Z]+)[0-9]+\z/D',$ref,$match))continue;
                $col=0;
                foreach(str_split($match[1]) as $letter)$col=$col*26+ord($letter)-64;
                if($col<1||$col>self::MAX_COLUMNS)throw new RuntimeException('اکسل حداکثر ۸۰ ستون می‌تواند داشته باشد.');
                $type=(string)$cell['t'];
                $value=(string)$cell->v;
                if($type==='s')$value=$shared[(int)$value]??'';
                if($type==='inlineStr'){
                    $value='';foreach((array)$cell->xpath('./*[local-name()="is"]//*[local-name()="t"]') as $part)$value.=(string)$part;
                }
                $values[$col-1]=$value;
            }
            if(!$values)continue;
            $width=max(array_keys($values))+1;
            $record=array_fill(0,$width,'');
            foreach($values as $column=>$value)$record[$column]=$value;
            $all[]=$record;
            if(count($all)>self::MAX_ROWS+1)throw new RuntimeException('فایل بیشتر از ۵۰۰۰ ردیف دارد.');
        }
        if(!$all)return [[],[],0];
        $headers=array_shift($all);
        $rows=[];$malformed=0;
        foreach($all as $record){
            if(count($record)>count($headers)){
                $overflow=array_slice($record,count($headers));
                if(count(array_filter($overflow,static fn($value)=>trim((string)$value)!==''))>0){$malformed++;continue;}
            }
            $rows[]=array_pad(array_slice($record,0,count($headers)),count($headers),'');
        }
        return [$headers,$rows,$malformed];
    }

    /** Public pure helper so ID mapping can be regression-tested without WordPress. */
    public static function valid_column($value,$total) {
        if(!is_scalar($value)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$value))return false;
        return (int)$value >= 0 && (int)$value < $total;
    }

    /** The template accepts ONLY built-in person fields and numbered sheet variables. */
    public static function template_error($template,$count,$national_column) {
        if(!is_string($template)||trim($template)===''||QYS_Text_Normalizer::chars($template)>3500)return 'متن پیام باید بین ۱ تا ۳۵۰۰ نویسه باشد.';
        if(preg_match_all('/\{([^{}]+)\}/u',$template,$matches)===false)return 'متن پیام معتبر نیست.';
        foreach($matches[1] as $token){
            if(in_array($token,['نام','نام خانوادگی'],true))continue;
            if(!preg_match('/\Aستون([1-9][0-9]*)\z/uD',$token,$m))return 'متغیر «'.$token.'» معتبر نیست؛ از ستون‌های نمایش‌داده‌شده استفاده کنید.';
            $index=(int)$m[1]-1;
            if($index >= $count)return 'ستون استفاده‌شده در متن پیام در فایل وجود ندارد.';
            if($index===$national_column)return 'ستون کد ملی نباید وارد متن پیام شود؛ فقط برای تطبیق مخاطب استفاده می‌شود.';
        }
        return '';
    }

    private static function followup_table(): string { return QYS_Table_Names::name('excel_followup'); }

    private static function followup_ready(): bool { return QYS_Table_Names::runtime_exists(self::followup_table()); }

    /** Detect only conservative, well-known identity/contact headers. Arbitrary spreadsheet columns are never retained. */
    public static function identity_columns(array $headers): array {
        $aliases=[
            'first'=>['نام','firstname','givenname'],
            'last'=>['نامخانوادگی','نامخانوادگي','lastname','surname','familyname','فامیل','فاميلي'],
            'full'=>['نامونامخانوادگی','نامونامخانوادگي','نامکامل','fullname','personname'],
            'mobile'=>['موبایل','موبايل','شماره‌موبایل','شماره‌موبايل','شمارهموبایل','شمارهموبايل','تلفنهمراه','شمارههمراه','mobile','mobilenumber','cellphone','phonenumber'],
        ];
        $out=['first'=>null,'last'=>null,'full'=>null,'mobile'=>null];
        foreach($headers as $i=>$header){
            $normalized=QYS_Text_Normalizer::header((string)$header);
            foreach($aliases as $key=>$values)if($out[$key]===null && in_array($normalized,$values,true))$out[$key]=(int)$i;
        }
        return $out;
    }

    private static function source_person_name(array $row,array $columns): string {
        if($columns['full']!==null){
            $name=trim(sanitize_text_field((string)($row[$columns['full']]??'')));
            if($name!=='')return QYS_Text_Normalizer::clip($name,190);
        }
        $first=$columns['first']!==null?trim(sanitize_text_field((string)($row[$columns['first']]??''))):'';
        $last=$columns['last']!==null?trim(sanitize_text_field((string)($row[$columns['last']]??''))):'';
        return QYS_Text_Normalizer::clip(trim($first.' '.$last),190);
    }

    private static function source_mobile(array $row,array $columns): string {
        if($columns['mobile']===null)return '';
        return QYS_Text_Normalizer::mobile((string)($row[$columns['mobile']]??''));
    }

    private static function source_national_reference($raw): string {
        $digits=preg_replace('/\D/u','',QYS_Text_Normalizer::digits((string)$raw));
        return QYS_Text_Normalizer::clip((string)$digits,32);
    }

    private static function followup_snapshot(array $row,int $source_row,string $reason,$member,array $columns,string $national_reference): array {
        $name='';$mobile='';$member_id=0;
        if(is_object($member)){
            $member_id=(int)($member->id??0);
            $name=trim((string)($member->first_name??'').' '.(string)($member->last_name??''));
            $mobile=QYS_Text_Normalizer::mobile((string)($member->mobile??''));
            if(!empty($member->national_id))$national_reference=(string)$member->national_id;
        }
        if($name==='')$name=self::source_person_name($row,$columns);
        if($mobile==='')$mobile=self::source_mobile($row,$columns);
        return [
            'source_row'=>max(0,$source_row),'member_id'=>$member_id,'person_name'=>QYS_Text_Normalizer::clip($name,190),
            'national_id'=>QYS_Text_Normalizer::clip($national_reference,32),'mobile'=>QYS_Text_Normalizer::clip($mobile,20),
            'reason'=>sanitize_key($reason),'created_at'=>current_time('mysql'),
        ];
    }

    private static function insert_followups(int $campaign_id,array $rows): bool { global $wpdb;
        if(!$rows)return true;
        if(!self::followup_ready())return false;
        foreach($rows as $row){
            $data=['campaign_id'=>$campaign_id]+$row;
            if($wpdb->insert(self::followup_table(),$data,['%d','%d','%d','%s','%s','%s','%s','%s'])!==1)return false;
        }
        return true;
    }

    private static function campaign_meta(int $campaign_id){ global $wpdb;
        if(!class_exists('QYS_Targeted')||!QYS_Targeted::schema_ready())return null;
        return $wpdb->get_row($wpdb->prepare('SELECT audience,audience_universities,manager_university FROM '.QYS_Targeted::table().' WHERE campaign_id=%d',$campaign_id));
    }

    public static function is_excel_campaign(int $campaign_id): bool {
        $meta=self::campaign_meta($campaign_id);
        return $meta && (string)$meta->audience==='excel';
    }

    private static function sensitive_allowed(int $campaign_id): bool {
        $meta=self::campaign_meta($campaign_id);if(!$meta)return false;
        $scope=QYS_Permission_Service::scope('sensitive_identity');
        if($scope==='*')return true;
        $universities=array_values(array_filter(explode(',',(string)$meta->audience_universities)));
        return is_string($scope)&&count($universities)===1&&$scope===$universities[0];
    }

    private static function import_reason_label(string $reason): string {
        $labels=[
            'invalid_id'=>'کد ملی نامعتبر؛ تطبیق انجام نشد',
            'duplicate_id'=>'کد ملی در فایل تکراری است',
            'not_registered'=>'در پرونده این دانشگاه یافت نشد؛ احتمالاً قرآن‌یار را فعال نکرده است',
            'quranyar_inactive'=>'قرآن‌یار یا دریافت پیام برای این فرد فعال نیست',
            'not_verified'=>'هویت عضو هنوز تأیید نشده است',
            'ambiguous_match'=>'چند پرونده با این شناسه تطبیق داده شد',
            'missing_variables'=>'متن شخصی‌سازی‌شده قابل ساخت نبود',
        ];
        return $labels[$reason]??'این ردیف وارد صف ارسال نشد';
    }

    private static function queue_reason_label(string $status,string $last_error): string {
        if($status===QYS_Delivery_State::PENDING)return 'هنوز در صف ارسال است';
        if($status===QYS_Delivery_State::SENDING)return 'در حال ارسال است؛ نتیجه نهایی هنوز ثبت نشده';
        if($status===QYS_Delivery_State::UNCERTAIN)return 'نتیجه تحویل نامشخص است؛ برای جلوگیری از پیام تکراری، ارسال مجدد خودکار انجام نمی‌شود';
        if($status===QYS_Delivery_State::RETRYABLE_FAILED)return 'خطای موقت ثبت شده است؛ این تلاش ناموفق بوده ولی از نظر فنی قابل بررسی برای تلاش مجدد است';
        if($status===QYS_Delivery_State::DEFINITIVE_FAILED)return 'ارسال در این تلاش به‌صورت قطعی ناموفق ثبت شده است';
        if($last_error==='member_ineligible')return 'در زمان ارسال، قرآن‌یار/مجوز پیام یا تأیید هویت فعال نبود';
        if($last_error==='tenant_authorization_revoked')return 'مجوز ارسال دانشگاهی پس از ایجاد کارزار تغییر کرده است';
        if(str_contains($last_error,'reason=chat_not_found'))return 'گفت‌وگوی خصوصی با برنامک فعال نیست یا کاربر در ایتا در دسترس نیست';
        if(str_contains($last_error,'reason=permission_denied'))return 'دسترسی پیام خصوصی مسدود یا رد شده است';
        if(str_contains($last_error,'reason=rate_limited'))return 'محدودیت موقت سرویس ارسال؛ ابتدا ارسال سامانه‌ای را دوباره بررسی کنید';
        if(str_contains($last_error,'reason=invalid_token')||$last_error==='app_token_not_configured')return 'خطای تنظیمات سرویس؛ مشکل از مخاطب نیست';
        if($last_error==='network_failure')return 'خطای شبکه؛ وضعیت تحویل قطعی نیست و نباید فوراً دوباره ارسال شود';
        return QYS_Messenger::safe_failure_label($last_error);
    }

    private static function followup_action(string $source,string $reason,string $status,string $last_error): string {
        if($source==='queue'&&in_array($status,[QYS_Delivery_State::PENDING,QYS_Delivery_State::SENDING],true))return 'فعلاً منتظر پایان صف';
        if(in_array($reason,['invalid_id','duplicate_id','ambiguous_match','missing_variables'],true))return 'ابتدا فایل/تطبیق را اصلاح کنید';
        if($source==='queue'&&$status===QYS_Delivery_State::UNCERTAIN)return 'ابتدا وضعیت تحویل را بررسی کنید؛ برای جلوگیری از پیام تکراری، دوباره ارسال نکنید';
        if($source==='queue'&&$status===QYS_Delivery_State::RETRYABLE_FAILED)return 'ابتدا وضعیت فنی را بررسی کنید؛ تلاش مجدد باید کنترل‌شده انجام شود';
        if($source==='queue' && ($last_error==='network_failure'||str_contains($last_error,'reason=rate_limited')||str_contains($last_error,'reason=invalid_token')||$last_error==='app_token_not_configured'))return 'ابتدا وضعیت فنی ارسال را بررسی کنید';
        return 'برای پیام حساس، روش جایگزین بررسی شود';
    }

    private static function mask_value(string $value): string {
        $value=trim($value);if($value==='')return '—';
        $len=strlen($value);if($len<=4)return str_repeat('•',$len);
        return str_repeat('•',min(8,$len-4)).substr($value,-4);
    }

    /** Consolidated exception view: import-time exclusions + queue rows not accepted as sent. */
    public static function followup_rows(int $campaign_id): array { global $wpdb;
        if($campaign_id<=0||!QYS_Permission_Service::campaign_visible($campaign_id)||!self::is_excel_campaign($campaign_id))return [];
        $out=[];
        if(self::followup_ready()){
            $stored=(array)$wpdb->get_results($wpdb->prepare('SELECT source_row,member_id,person_name,national_id,mobile,reason,created_at FROM '.self::followup_table().' WHERE campaign_id=%d ORDER BY source_row,id',$campaign_id));
            foreach($stored as $r)$out[]=[
                'source'=>'import','source_row'=>(int)$r->source_row,'member_id'=>(int)$r->member_id,'person_name'=>(string)$r->person_name,
                'national_id'=>(string)$r->national_id,'mobile'=>(string)$r->mobile,'status'=>'not_queued','reason'=>(string)$r->reason,
                'reason_label'=>self::import_reason_label((string)$r->reason),'last_error'=>'','updated_at'=>(string)$r->created_at,
                'action'=>self::followup_action('import',(string)$r->reason,'not_queued',''),
            ];
        }
        $queue=QYS_Campaign_Repository::non_sent_rows($campaign_id,5000);
        $contacts=QYS_Member_Repository::followup_contacts_by_ids(array_map(static fn($r)=>(int)$r->member_id,$queue));
        foreach($queue as $r){
            $m=$contacts[(int)$r->member_id]??null;
            $name=$m?trim((string)$m->first_name.' '.(string)$m->last_name):'عضو شماره '.(int)$r->member_id;
            $last=(string)$r->last_error;$status=(string)$r->status;
            $out[]=[
                'source'=>'queue','source_row'=>0,'member_id'=>(int)$r->member_id,'person_name'=>$name,
                'national_id'=>$m?(string)$m->national_id:'','mobile'=>$m?(string)$m->mobile:'','status'=>$status,'reason'=>'',
                'reason_label'=>self::queue_reason_label($status,$last),'last_error'=>$last,'updated_at'=>(string)$r->updated_at,
                'action'=>self::followup_action('queue','',$status,$last),
            ];
        }
        return $out;
    }

    /** Phase D / D8: keyset batch for import-time follow-up rows. */
    private static function followup_import_export_chunk(int $campaign_id,int $after_id,int $limit): array { global $wpdb;
        if($campaign_id<=0||!self::followup_ready())return [];
        return (array)$wpdb->get_results($wpdb->prepare(
            'SELECT id,source_row,member_id,person_name,national_id,mobile,reason,created_at FROM '.self::followup_table().' WHERE campaign_id=%d AND id>%d ORDER BY id ASC LIMIT %d',
            $campaign_id,max(0,$after_id),max(1,min(500,$limit))
        ));
    }

    private static function followup_export_row(array $r): array {
        return [
            $r['source_row']?:'',(string)$r['person_name'],(string)$r['national_id'],(string)$r['mobile'],(string)$r['status'],
            (string)$r['reason_label'],(string)$r['action'],(string)$r['updated_at'],
        ];
    }

    public static function render_followup_report(int $campaign_id): void {
        if(!self::is_excel_campaign($campaign_id)||!QYS_Permission_Service::campaign_visible($campaign_id))return;
        $rows=self::followup_rows($campaign_id);$counts=QYS_Campaign_Repository::status_counts_for_campaign($campaign_id);
        $in_progress=(int)$counts['pending']+(int)$counts['sending'];
        $final_followup=0;$stored_import=0;foreach($rows as $r){if($r['source']==='import')$stored_import++;if(!in_array($r['status'],['pending','sending'],true))$final_followup++;}
        $preview=QYS_Campaign_Repository::excel_preview($campaign_id,QYS_Table_Names::name('import_reports'));
        $excluded_total=$preview?max(0,(int)$preview->input_rows-(int)$preview->queued_rows):0;
        $unidentified=max(0,$excluded_total-$stored_import);
        $sensitive=self::sensitive_allowed($campaign_id);
        echo '<section style="margin-top:22px;padding:18px;border:1px solid #dcdcde;background:#fff;border-radius:10px">';
        echo '<h2 style="margin-top:0">افراد نیازمند پیگیری پس از ارسال اکسل</h2>';
        echo '<p>ارسال‌شده: <strong>'.(int)$counts['sent'].'</strong> | در حال پردازش: <strong>'.$in_progress.'</strong> | نیازمند بررسی/روش جایگزین: <strong>'.$final_followup.'</strong>'.($unidentified?' | ردیف غیرقابل شناسایی: <strong>'.$unidentified.'</strong>':'').'</p>';
        if($unidentified)echo '<p class="description">'.$unidentified.' ردیفِ کنارگذاشته‌شده جزئیات فردی قابل بازیابی ندارد؛ معمولاً ردیف CSV نامعتبر است یا کارزار قبل از فعال‌شدن این گزارش ساخته شده است. برای این موارد فایل اصلی را بررسی کنید.</p>';
        if(!$rows){echo '<p><strong>مورد ارسال‌نشده یا کنارگذاشته‌شده‌ای برای این کارزار ثبت نشده است.</strong></p></section>';return;}
        if($sensitive){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:10px 0 14px"><input type="hidden" name="action" value="qys_excel_followup_export"><input type="hidden" name="campaign_id" value="'.(int)$campaign_id.'">';
            wp_nonce_field('qys_excel_followup_export_'.$campaign_id);
            echo '<button class="button button-primary" type="submit">دانلود CSV افراد ارسال‌نشده / نیازمند پیگیری</button></form>';
        }else{
            echo '<p class="description">برای مشاهده و خروجی موبایل و کد ملی کامل، مجوز «مشاهده کد ملی و موبایل کامل» لازم است. در این صفحه شناسه‌ها ماسک شده‌اند.</p>';
        }
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>فرد / ردیف فایل</th><th>کد ملی</th><th>موبایل</th><th>وضعیت/دلیل</th><th>اقدام</th></tr></thead><tbody>';
        foreach($rows as $r){
            $label=trim((string)$r['person_name']);if($label==='')$label=$r['source_row']>0?'ردیف '.$r['source_row'].' فایل':'عضو';
            if($r['source_row']>0)$label.=' — ردیف '.$r['source_row'];
            $nid=$sensitive?((string)$r['national_id']!==''?(string)$r['national_id']:'—'):self::mask_value((string)$r['national_id']);
            $mobile=$sensitive?((string)$r['mobile']!==''?(string)$r['mobile']:'—'):self::mask_value((string)$r['mobile']);
            echo '<tr><td>'.esc_html($label).'</td><td dir="ltr">'.esc_html($nid).'</td><td dir="ltr">'.esc_html($mobile).'</td><td>'.esc_html((string)$r['reason_label']).'</td><td><strong>'.esc_html((string)$r['action']).'</strong></td></tr>';
        }
        echo '</tbody></table></div><p class="description">ردیف‌های «در صف» یا «در حال ارسال» هنوز نتیجه نهایی ندارند. وضعیت «نتیجه تحویل نامشخص» نیز نباید بدون بررسی دوباره ارسال شود؛ این تفکیک برای جلوگیری از پیام تکراری است.</p></section>';
    }

    private static function csv_safe($value): string {
        $value=(string)$value;
        if($value!==''&&preg_match('/^[=+\-@]/u',$value))$value="'".$value;
        return $value;
    }

    public static function export_followup(): void {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('درخواست نامعتبر است.',405);
        $campaign_id=absint($_POST['campaign_id']??0);
        check_admin_referer('qys_excel_followup_export_'.$campaign_id);
        if(!$campaign_id||!QYS_Permission_Service::campaign_visible($campaign_id)||!self::is_excel_campaign($campaign_id))wp_die('دسترسی به این گزارش مجاز نیست.',403);
        if(!self::sensitive_allowed($campaign_id))wp_die('برای خروجی اطلاعات تماس، مجوز مشاهده اطلاعات هویتی حساس لازم است.',403);
        try{$h=QYS_Batch_Export::open_csv('quranyar-excel-followup-'.$campaign_id.'-'.gmdate('Ymd-His').'.csv',['ردیف فایل','نام','کد ملی','موبایل','وضعیت','دلیل','اقدام','آخرین به‌روزرسانی']);}
        catch(Throwable $e){wp_die('ساخت خروجی ممکن نیست.',500);}
        $size=QYS_Batch_Export::chunk_size();$after_id=0;
        do{
            $rows=self::followup_import_export_chunk($campaign_id,$after_id,$size);
            foreach($rows as $r){
                $after_id=max($after_id,(int)$r->id);
                $row=[
                    'source_row'=>(int)$r->source_row,'person_name'=>(string)$r->person_name,'national_id'=>(string)$r->national_id,'mobile'=>(string)$r->mobile,
                    'status'=>'not_queued','reason_label'=>self::import_reason_label((string)$r->reason),'action'=>self::followup_action('import',(string)$r->reason,'not_queued',''),'updated_at'=>(string)$r->created_at,
                ];
                QYS_Batch_Export::write_csv_row($h,array_map([__CLASS__,'csv_safe'],self::followup_export_row($row)));
            }
            if($rows)QYS_Batch_Export::flush_chunk($h);
        }while(count($rows)===$size);

        $after_id=0;
        do{
            $rows=QYS_Campaign_Repository::non_sent_rows_after($campaign_id,$after_id,$size);
            if(!$rows)break;
            $contacts=QYS_Member_Repository::followup_contacts_by_ids(array_map(static fn($r)=>(int)$r->member_id,$rows));
            foreach($rows as $r){
                $after_id=max($after_id,(int)$r->id);$m=$contacts[(int)$r->member_id]??null;
                $name=$m?trim((string)$m->first_name.' '.(string)$m->last_name):'عضو شماره '.(int)$r->member_id;
                $last=(string)$r->last_error;$status=(string)$r->status;
                $row=[
                    'source_row'=>0,'person_name'=>$name,'national_id'=>$m?(string)$m->national_id:'','mobile'=>$m?(string)$m->mobile:'','status'=>$status,
                    'reason_label'=>self::queue_reason_label($status,$last),'action'=>self::followup_action('queue','',$status,$last),'updated_at'=>(string)$r->updated_at,
                ];
                QYS_Batch_Export::write_csv_row($h,array_map([__CLASS__,'csv_safe'],self::followup_export_row($row)));
            }
            QYS_Batch_Export::flush_chunk($h);
        }while(count($rows)===$size);
        QYS_Batch_Export::close($h);exit;
    }

    /** Authorization must be campus-only even when the owner also has global powers. */
    private static function campus_allowed() {
        $uni=QYS_Tenant_Context::campus();
        if(!$uni || !QYS_Multitenant::university_active($uni))return '';
        return QYS_Permission_Service::scope('send_messages')===$uni?$uni:'';
    }

    public static function preview_headers() {
        if(!is_user_logged_in())wp_send_json_error('ورود نماینده لازم است.',403);
        check_ajax_referer('qys_excel_headers','nonce');
        $uni=self::campus_allowed();
        if(!$uni)wp_send_json_error('مجوز ارسال برای دانشگاه خودتان ندارید.',403);
        try{
            $file=$_FILES['dataset']??null;
            $ext=self::check_file($file);
            $data=self::read_dataset($file['tmp_name'],$ext);
            if(!$data['rows'])throw new RuntimeException('هیچ ردیف داده‌ای در فایل یافت نشد.');
            // Never send raw values (names, national IDs, phone numbers) to the mapping UI.
            wp_send_json_success([
                'headers'=>$data['headers'],
                'rows'=>count($data['rows']),
                'malformed'=>$data['malformed'],
                'digest'=>hash_file('sha256',$file['tmp_name']),
            ]);
        }catch(Throwable $e){wp_send_json_error($e->getMessage(),400);}
    }

    public static function create_post() {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('درخواست نامعتبر است.',405);
        check_admin_referer('qys_excel_create');
        $uni=self::campus_allowed();
        if(!$uni)wp_die('این حساب مجوز ارسال اکسل دانشگاه را ندارد.',403);
        $result=self::create($uni,wp_unslash($_POST),$_FILES['dataset']??null);
        // A single-use notice per user, with no personal data or spreadsheet values.
        $notice_key='qys_excel_notice_'.get_current_user_id();
        set_transient($notice_key,$result['message'],90);
        $args=[];
        if(!empty($result['campaign_id']))$args['draft']=(int)$result['campaign_id'];
        wp_safe_redirect(QYS_Multitenant::campus_url('excel',$args));exit;
    }

    /** Build a tenant-scoped draft and an aggregate report atomically; NO outbound send. */
    public static function create($uni,$input,$file) {global $wpdb;
        if(!$uni || $uni!==QYS_Tenant_Context::campus() || self::campus_allowed()!==$uni)return ['message'=>'مجوز ارسال دانشگاه تأیید نشد.'];
        if(!QYS_Targeted::schema_ready() && !QYS_Targeted::ensure_schema())return ['message'=>'جدول ارسال هدفمند آماده نیست.'];
        if(!QYS_Table_Names::runtime_exists(QYS_Table_Names::name('import_reports'))||!self::followup_ready())return ['message'=>'جدول گزارش تطبیق/پیگیری فایل آماده نیست. یک‌بار با مدیر کل وارد پیشخوان شوید تا ارتقای پایگاه‌داده کامل شود.'];
        $key=sanitize_text_field($input['request_key']??'');
        if(!preg_match('/\A[a-f0-9-]{36}\z/D',$key))return ['message'=>'شناسه درخواست نامعتبر است. صفحه را مجدداً باز کنید.'];
        $label=QYS_Text_Normalizer::clip(sanitize_text_field($input['label']??''),190);
        $template=sanitize_textarea_field($input['template']??'');
        $mode=sanitize_key($input['mode']??'');
        if($label===''||!in_array($mode,['immediate','scheduled'],true))return ['message'=>'عنوان یا روش ارسال معتبر نیست.'];
        $scheduled=null;
        if($mode==='scheduled') {
            $scheduled=QYS_Targeted::parse_schedule($input['send_day']??'',$input['send_hour']??'',$input['send_minute']??'');
            if(!$scheduled)return ['message'=>'تاریخ یا ساعت زمان‌بندی نامعتبر است یا در گذشته قرار دارد.'];
        }
        try {
            $ext=self::check_file($file);
            $data=self::read_dataset($file['tmp_name'],$ext);
            if(!$data['rows'])throw new RuntimeException('فایل ردیف قابل پردازش ندارد.');
            $fingerprint=is_string($input['file_digest']??null)?$input['file_digest']:'';
            if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint) || !hash_equals($fingerprint,hash_file('sha256',$file['tmp_name'])))throw new RuntimeException('فایل پس از مرحله تطبیق تغییر کرده است؛ مجدداً فایل را بارگذاری کنید.');
            $columns=count($data['headers']);
            if(!self::valid_column($input['national_column']??null,$columns))throw new RuntimeException('ستون کد ملی را با کشیدن ستون فایل به کادر تعیین کنید.');
            $national=(int)$input['national_column'];
            $problem=self::template_error($template,$columns,$national);
            if($problem)throw new RuntimeException($problem);
        }catch(Throwable $e){return ['message'=>$e->getMessage()];}
        $counts=array_fill_keys(['invalid_id','duplicate_id','unmatched_id','ineligible_member','ambiguous_match','missing_variables','queue_error'],0);
        $identity_columns=self::identity_columns($data['headers']);
        $seen=[];$valid_rows=[];$followups=[];
        foreach($data['rows'] as $index=>$row) {
            $source_row=(int)$index+2;
            $raw_reference=self::source_national_reference((string)($row[$national]??''));
            $national_id=QYS_Text_Normalizer::national_id((string)($row[$national]??''));
            if($national_id===''){
                $counts['invalid_id']++;
                $followups[]=self::followup_snapshot($row,$source_row,'invalid_id',null,$identity_columns,$raw_reference);
                continue;
            }
            if(isset($seen[$national_id])){
                $counts['duplicate_id']++;
                $followups[]=self::followup_snapshot($row,$source_row,'duplicate_id',null,$identity_columns,$national_id);
                continue;
            }
            $seen[$national_id]=true;
            $valid_rows[]=['national_id'=>$national_id,'values'=>$row,'source_row'=>$source_row];
        }
        // Use bounded tenant-scoped batch lookups rather than up to 5,000 SQL queries.
        $matches_by_id=[];
        foreach(array_chunk(array_keys($seen),200) as $batch){
            $members=QYS_Member_Repository::match_national_ids($uni,$batch);
            foreach($members as $member)$matches_by_id[(string)$member->national_id][]=$member;
        }
        $ready=[];
        foreach($valid_rows as $item){
            $matches=$matches_by_id[$item['national_id']]??[];
            if(!$matches){
                $counts['unmatched_id']++;
                $followups[]=self::followup_snapshot($item['values'],(int)$item['source_row'],'not_registered',null,$identity_columns,$item['national_id']);
                continue;
            }
            if(count($matches)!==1){
                $counts['ambiguous_match']++;
                $followups[]=self::followup_snapshot($item['values'],(int)$item['source_row'],'ambiguous_match',null,$identity_columns,$item['national_id']);
                continue;
            }
            $member=$matches[0];
            if(!(int)$member->consent){
                $counts['ineligible_member']++;
                $followups[]=self::followup_snapshot($item['values'],(int)$item['source_row'],'quranyar_inactive',$member,$identity_columns,$item['national_id']);
                continue;
            }
            if(!(int)$member->verified){
                $counts['ineligible_member']++;
                $followups[]=self::followup_snapshot($item['values'],(int)$item['source_row'],'not_verified',$member,$identity_columns,$item['national_id']);
                continue;
            }
            $variables=['نام'=>$member->first_name,'نام خانوادگی'=>$member->last_name];
            foreach($item['values'] as $i=>$value)if($i!==$national)$variables['ستون'.($i+1)]=(string)$value;
            $message=QYS_Text_Normalizer::render_template($template,$variables);
            if($message===null || trim($message)===''){
                $counts['missing_variables']++;
                $followups[]=self::followup_snapshot($item['values'],(int)$item['source_row'],'missing_variables',$member,$identity_columns,$item['national_id']);
                continue;
            }
            $ready[]=['member_id'=>(int)$member->id,'payload'=>$message];
        }
        $reports=QYS_Table_Names::name('import_reports');
        if(!QYS_Campaign_Repository::begin())return ['message'=>'امکان آغاز تراکنش وجود ندارد؛ پیش‌نویسی ساخته نشد.'];
        try {
            $id=QYS_Campaign_Repository::create_draft($label,$template);
            if($id===false)throw new RuntimeException('campaign');
            $meta=[
                'campaign_id'=>$id,'request_key'=>$key,'audience'=>'excel',
                'audience_groups'=>'','audience_universities'=>$uni,'manager_university'=>$uni,
                'scheduled_at_utc'=>$scheduled['utc']??null,
                'scheduled_jalali'=>$scheduled['jalali']??'',
                'scheduled_hm'=>$scheduled['hm']??'',
                'created_by'=>get_current_user_id(),'created_at_utc'=>gmdate('Y-m-d H:i:s')
            ];
            if($wpdb->insert(QYS_Targeted::table(),$meta,['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s'])!==1)throw new RuntimeException('meta');
            foreach($ready as $record){
                if(!QYS_Campaign_Repository::enqueue((int)$id,(int)$record['member_id'],(string)$record['payload']))throw new RuntimeException('queue');
            }
            if(!self::insert_followups((int)$id,$followups))throw new RuntimeException('followup');
            $report=['campaign_id'=>$id,'input_rows'=>count($data['rows'])+$data['malformed'],
                'readable_rows'=>count($data['rows']),'queued_rows'=>count($ready),
                'malformed_csv'=>$data['malformed'],'created_at'=>current_time('mysql')]+$counts;
            if($wpdb->insert($reports,$report)!==1)throw new RuntimeException('report');
            if(!QYS_Campaign_Repository::commit())throw new RuntimeException('commit');
        }catch(Throwable $e){
            QYS_Campaign_Repository::rollback();
            return ['message'=>'ذخیره پیش‌نویس یا گزارش تطبیق ناموفق بود؛ هیچ پیامی فعال نشد.'];
        }
        return [
            'message'=>sprintf('پیش‌نویس شماره %d ساخته شد: %d گیرنده آماده؛ %d ردیف کنار گذاشته شد. گزارش افراد ارسال‌نشده برای پیگیری ذخیره شد. هیچ پیامی ارسال نشده است.',$id,count($ready),count($data['rows'])+$data['malformed']-count($ready)),
            'campaign_id'=>$id
        ];
    }

    public static function page() {global $wpdb;
        $uni=self::campus_allowed();
        if(!$uni){echo '<section class="qys-tenant-state">مجوز ارسال از اکسل برای این دانشگاه فعال نیست.</section>';return;}
        $notice_key='qys_excel_notice_'.get_current_user_id();
        $notice=get_transient($notice_key);
        if($notice!==false)delete_transient($notice_key);
        echo '<div class="qys-admin qys-excel" dir="rtl">'
          .'<div class="qys-excel-header"><div><span class="qys-excel-overline">ارسال اختصاصی دانشگاه</span><h1>ساخت پیام با فایل اکسل</h1><p>فایل را بارگذاری کنید، ستون‌ها را روی مقصد بکشید و متن پیام هر نفر را بسازید. تا تأیید جداگانه شما هیچ پیامی ارسال نمی‌شود.</p></div><a class="qys-excel-back" href="'.esc_url(QYS_Multitenant::campus_url('messages')).'">بازگشت به پیام‌ها</a></div>';
        if(is_string($notice)&&$notice!=='')echo '<div class="qys-excel-alert" role="status">'.esc_html($notice).'</div>';
        $draft=absint($_GET['draft']??0);
        if($draft && QYS_Permission_Service::campaign_visible($draft))self::draft_preview($draft);
        $tomorrow=new DateTimeImmutable('tomorrow',new DateTimeZone('Asia/Tehran'));
        echo '<form id="qys-excel-form" class="qys-excel-form" method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="qys_excel_create">'.QYS_Multitenant::portal_scope_fields();
        wp_nonce_field('qys_excel_create');
        echo '<input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'">'
          .'<input type="hidden" name="file_digest" id="qys-excel-digest" value="">'
          .'<input type="hidden" name="national_column" id="qys-excel-national" value="">'
          .'<section class="qys-excel-card"><div class="qys-excel-step"><b>۱</b><div><h2>بارگذاری فایل Excel یا CSV</h2><p>فایل باید ردیف اولِ عنوان ستون‌ها و حداکثر ۵۰۰۰ ردیف داده داشته باشد. کد ملی را در اکسل به‌صورت «متن» ذخیره کنید تا صفر ابتدایی حذف نشود.</p></div></div>'
          .'<div class="qys-excel-drop" id="qys-excel-file-drop" tabindex="0" role="button" aria-label="بارگذاری فایل اکسل"><strong>فایل را اینجا رها کنید</strong><span>یا برای انتخاب فایل کلیک کنید</span><small>CSV UTF-8 / XLSX — حداکثر ۵ مگابایت</small><input name="dataset" id="qys-excel-file" type="file" accept=".csv,.xlsx" required></div>'
          .'<div id="qys-excel-status" role="status" aria-live="polite"></div></section>';
        echo '<section id="qys-excel-mapping" class="qys-excel-card" hidden><div class="qys-excel-step"><b>۲</b><div><h2>اتصال ستون‌ها با کشیدن و رهاکردن</h2><p>ستون کد ملی را روی کادر شناسایی مخاطب بکشید. سپس ستون‌های دلخواه را به محل مناسب متن پیام بکشید یا روی آن‌ها کلیک کنید.</p></div></div>'
          .'<div class="qys-excel-mapgrid"><div><h3>ستون‌های فایل</h3><div id="qys-excel-columns" class="qys-excel-chips" aria-label="ستون‌های قابل جابه‌جایی"></div></div><div><h3>شناسایی گیرنده</h3><div id="qys-excel-id-drop" class="qys-excel-id-drop" tabindex="0">ستون «کد ملی» را اینجا رها کنید</div><label for="qys-excel-id-select">یا ستون کد ملی را انتخاب کنید</label><select id="qys-excel-id-select"><option value="">انتخاب ستون</option></select></div></div>'
          .'<p class="qys-excel-help" id="qys-excel-help">با کلیک روی هر ستون، متغیر آن در محل نشانگر متن پیام قرار می‌گیرد. برای جابه‌جایی با لمس نیز از فهرست انتخاب استفاده کنید.</p></section>';
        echo '<section id="qys-excel-compose" class="qys-excel-card" hidden><div class="qys-excel-step"><b>۳</b><div><h2>متن و زمان ارسال</h2><p>متغیرهای {نام} و {نام خانوادگی} از پرونده تأییدشده عضو خوانده می‌شوند؛ ستون‌های فایل، جای خالی‌های متن اختصاصی را پر می‌کنند.</p></div></div>'
          .'<label for="qys-excel-label">عنوان داخلی پیام</label><input id="qys-excel-label" name="label" maxlength="190" required placeholder="مثلاً: نتیجه آزمون حفظ جزء سی">'
          .'<div class="qys-excel-quick"><button type="button" data-token="{نام}">افزودن نام</button><button type="button" data-token="{نام خانوادگی}">افزودن نام خانوادگی</button></div>'
          .'<label for="qys-excel-template">متن پیام؛ ستون‌ها را داخل کادر رها کنید</label><textarea id="qys-excel-template" name="template" rows="7" maxlength="3500" required placeholder="سلام {نام} عزیز، نتیجه شما: ..."></textarea>'
          .'<div class="qys-excel-schedule"><label><input type="radio" name="mode" value="immediate" checked> فوری، فقط پس از تأیید پیش‌نویس</label><label><input type="radio" name="mode" value="scheduled"> زمان‌بندی‌شده</label></div>'
          .'<div id="qys-excel-time" hidden><label>تاریخ شمسی <input name="send_day" inputmode="numeric" dir="ltr" maxlength="10" value="'.esc_attr(QYS_Calendar_Service::jalali_from_date($tomorrow)).'"></label><label>ساعت <select name="send_hour">';
        for($h=0;$h<24;$h++)echo '<option value="'.sprintf('%02d',$h).'"'.selected($h,9,false).'>'.sprintf('%02d',$h).'</option>';
        echo '</select></label><label>دقیقه <select name="send_minute">';
        for($i=0;$i<60;$i++)echo '<option value="'.sprintf('%02d',$i).'">'.sprintf('%02d',$i).'</option>';
        echo '</select></label></div><p class="qys-excel-fine">تاریخ و ساعت به وقت ایران است. پس از ساخت پیش‌نویس، متن هر گیرنده را بررسی و ارسال را جداگانه فعال کنید.</p>'
          .'<button class="qys-excel-submit" type="submit" id="qys-excel-submit" disabled>ایجاد پیش‌نویس، بدون ارسال</button></section></form></div>';
    }

    private static function draft_preview($cid){global $wpdb;
        $report=QYS_Table_Names::name('import_reports');
        $row=QYS_Campaign_Repository::excel_preview((int)$cid,$report);
        if(!$row)return;
        echo '<section class="qys-excel-card qys-excel-preview"><h2>گزارش تطبیق پیش‌نویس شماره '.(int)$cid.'</h2>'
          .'<p><strong>'.esc_html($row->label).'</strong> — وضعیت: '.esc_html($row->state).'</p>'
          .'<div class="qys-excel-stats"><div><b>'.(int)$row->input_rows.'</b><span>ردیف فایل</span></div><div><b>'.(int)$row->queued_rows.'</b><span>گیرنده آماده</span></div><div><b>'.max(0,(int)$row->input_rows-(int)$row->queued_rows).'</b><span>ردیف کنارگذاشته‌شده</span></div></div>';
        $reasons=['invalid_id'=>'کد ملی نامعتبر','duplicate_id'=>'کد ملی تکراری','unmatched_id'=>'عضو متعلق به این دانشگاه یافت نشد','ineligible_member'=>'فاقد تأیید یا مجوز دریافت','ambiguous_match'=>'چند تطبیق','missing_variables'=>'متغیر ناموجود یا متن طولانی','malformed_csv'=>'ردیف CSV نامعتبر'];
        echo '<p class="qys-excel-fine">';foreach($reasons as $field=>$label)if(!empty($row->$field))echo esc_html($label).': '.(int)$row->$field.' | ';echo '</p>';
        $items=QYS_Campaign_Repository::queued_payloads((int)$cid,5);
        if($items){echo '<h3>نمونه متن‌های آماده ارسال (حداکثر پنج مورد)</h3>';foreach($items as $item)echo '<pre class="qys-excel-payload">'.esc_html($item->payload).'</pre>';}
        echo '<p><a class="qys-excel-back" href="'.esc_url(QYS_Multitenant::campus_url('messages')).'">برای بررسی جزئیات، تأیید نهایی و گزارش افراد ارسال‌نشده، به بخش پیام‌ها بروید</a></p>'
          .'<p class="qys-excel-fine">اطلاعات خام فایل ذخیره نشده‌اند. این صفحه فقط برای نماینده مجاز همین دانشگاه قابل مشاهده است.</p></section>';
    }
}
