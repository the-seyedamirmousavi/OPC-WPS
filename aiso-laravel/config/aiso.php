<?php

/*
 * AISO application settings. Everything here is read from the environment; nothing secret is stored in the database.
 */

// What the owner and managers may ask the assistant to do with AI agents. Both are only proposals the user confirms.
$agentActions = [
    'create_agent' => [
        'risk' => 'high',
        'description' => 'یک ایجنت هوش مصنوعی می‌سازد: کاربر مجازی با نقش AGENT که وارد نمی‌شود، عملیات نمی‌گیرد و صندلی لایسنس نمی‌گیرد. شرح کار ثابت (instructions) و دپارتمان‌هایی که می‌تواند بخواند (scope؛ فقط از میان دپارتمان‌های همین کاربر) را می‌گیرد. اگر task بدهی، همان لحظه کار اولش را هم اجرا می‌کند و نتیجه را به‌صورت اعلان می‌فرستد. ایجنت فقط می‌خواند.',
        'calls' => 'AgentService::create و AgentService::assignTask',
        'params' => [
            'name' => 'required|string|max:100',
            'instructions' => 'required|string|max:1000',
            'task' => 'nullable|string|max:1000',
            'scope' => 'nullable|array',
            'scope.*' => 'string|max:40',
        ],
    ],
    'run_agent_task' => [
        'risk' => 'medium',
        'description' => 'کاری تازه به یک ایجنت موجود می‌دهد. فقط به ایجنت فعالِ ساخته‌ی خود کاربر (مالک: هر ایجنتی). ایجنت فقط می‌خواند و گزارش می‌دهد؛ نتیجه به‌صورت اعلان می‌رسد.',
        'calls' => 'AgentService::assignTask',
        'params' => [
            'agent_id' => 'required|string|max:64|exists:app_user,id',
            'task' => 'required|string|max:1000',
        ],
    ],
];

return [

    'seed' => [
        // Seeds OWNER, MANAGER, USER_1..3 on first start when the user table is empty.
        'enabled' => (bool) env('AISO_SEED_ENABLED', true),
        'password' => env('AISO_SEED_PASSWORD', 'ChangeMe!123'),
        // Language of system-generated texts on a fresh install ("fa" or "en"); the owner can change it in Settings.
        'language' => env('AISO_LANGUAGE', 'fa'),
        // Loads the sample workbook (resources/samples/simple-sample.xlsx) with the seed, so the screens are not empty.
        'demo_data' => (bool) env('AISO_SEED_DEMO_DATA', true),
        // Creates the production department (code PRODUCTION) with the users, so the planning pages have their workspace.
        'production_department' => (bool) env('AISO_SEED_PRODUCTION_DEPARTMENT', true),
        // A realistic development dataset (AisoRealisticSeeder). Off unless asked for: it is never part of a normal install.
        'realistic' => (bool) env('AISO_SEED_REALISTIC', false),
    ],

    'llm' => [
        // AI service: GapGPT, an OpenAI-compatible API (POST {base_url}/chat/completions with a Bearer token).
        // The owner normally enters the token and model in the owner console (stored encrypted, never sent to the
        // browser). These values are the fallback when nothing is stored there.
        'api_key' => env('AISO_LLM_API_KEY', ''),
        'model' => env('AISO_LLM_MODEL') ?: 'gapgpt-gemma-26b-a4b',
        'base_url' => env('AISO_LLM_BASE_URL') ?: 'https://api.gapgpt.app/v1',
        'timeout_seconds' => (int) env('AISO_LLM_TIMEOUT', 300),
        'max_tokens' => (int) env('AISO_LLM_MAX_TOKENS', 4096),
        'fallback_to_algorithm' => (bool) env('AISO_LLM_FALLBACK', true),
        // Embedding model of the same service (POST {base_url}/embeddings), used by the assistant to understand meaning.
        // Optional: if the model does not exist, or this is empty, the assistant uses its text matching only.
        'embedding_model' => env('AISO_LLM_EMBEDDING_MODEL') ?: 'text-embedding-3-small',
        // One embedding call covers the whole intent catalogue, so it needs more time than a short question would.
        'embedding_timeout_seconds' => (int) env('AISO_LLM_EMBEDDING_TIMEOUT', 30),
        // Where the embeddings come from when they are not the chat service: e.g. a local Ollama (http://127.0.0.1:11434/v1).
        // Empty means the chat service's own base URL and token. A local endpoint needs no token.
        'embedding_base_url' => rtrim((string) env('AISO_EMBEDDING_BASE_URL', ''), '/'),
    ],

    'http' => [
        // Certificate bundle file for outgoing HTTPS calls (the AI service, Bale). Needed when PHP has no curl.cainfo,
        // which is common on Windows ("cURL error 60"). Certificate checking is never switched off.
        'ca_bundle' => env('AISO_CA_BUNDLE', ''),
    ],

    'assignment' => [
        // Soft preference: the resource's responsible user is treated as if they had this many fewer load-hours.
        'responsible_bonus_hours' => (float) env('AISO_RESPONSIBLE_BONUS_HOURS', 8),
    ],

    /*
     * The AI agent (protocol aiso.v1). The AI never runs SQL or code; it answers in JSON and the backend decides. Only
     * what is listed here can be asked for: a department kind, its read intents (with the rules for their parameters)
     * and its actions (with the risk and who may do them). Departments themselves come from the database and are
     * filtered by what the user may open; the slug is "hr", "production" or "dept-{id}".
     */
    'agent' => [
        'max_rounds' => 3,
        'max_rows' => 50,
        'max_requests_per_round' => 5,
        'pending_ttl_minutes' => 10,
        'max_active_agents' => 20,
        // The first gate (IntentRouter): read, action, chat or clarify, before the route. One extra model call per question.
        // Turn it off with AISO_AGENT_GATE=false; the route then decides alone, as before.
        'gate' => ['enabled' => (bool) env('AISO_AGENT_GATE', true)],
        // Which engine answers: "dialogue" (stateful, tools read on demand, small context; App\AI\Dialogue) or "legacy"
        // (the earlier route / fetch / answer loop with the full briefing). Both use the same intents, checks and approvals.
        'engine' => env('AISO_AGENT_ENGINE', 'dialogue'),
        'dialogue' => [
            // model rounds in one turn (each may call tools) and tool calls per round
            'max_rounds' => 6,
            'max_calls_per_round' => 5,
            // autonomy: the assistant decides details itself from the organisation's data (defaults it states in its answer)
            // instead of asking; a question that is not about a missing required value is sent back to it once.
            // Changes are still only proposed and confirmed under the owner's approval policy.
            'autonomy' => (bool) env('AISO_AGENT_AUTONOMY', true),
            // a question waiting for a missing parameter is dropped after this long; the summary after state_ttl_hours
            'pending_ttl_minutes' => (int) env('AISO_DIALOGUE_PENDING_TTL', 30),
            'state_ttl_hours' => 24,
            // tool results are cut to fit this many characters before the model sees them (rows are dropped, never hidden)
            'max_result_chars' => 6000,
            // how many tool schemas are shown in full (the most relevant ones); every other tool is listed in one line
            'detailed_tools' => 5,
            // turns kept in the rolling summary and results kept for follow-up questions
            'summary_turns' => 6,
            'recent_results' => 3,
            // the full model input of each step is kept in ai_run_steps (the owner's assistant log). Off: only sizes.
            'log_payloads' => (bool) env('AISO_AGENT_LOG_PAYLOADS', true),
            // what to ask when a parameter is missing (the model's own question is used when it writes one)
            'questions' => [
                'user_id' => 'منظورت کدوم کاربره؟ اسم یا شناسه‌اش رو بگو.',
                'project_id' => 'کدوم پروژه رو می‌گی؟ اسم یا شناسه‌اش رو بگو.',
                'operation_id' => 'کدوم عملیات؟ اسم یا شناسه‌اش رو بگو.',
                'resource_id' => 'کدوم منبع؟ اسم یا شناسه‌اش رو بگو.',
                'department_id' => 'کدوم دپارتمان؟',
                'status' => 'کدوم وضعیت؟ (آماده، تخصیص‌یافته، در حال انجام، مسدود، تکمیل‌شده، لغوشده)',
                'from' => 'از چه تاریخی تا چه تاریخی؟',
                'to' => 'از چه تاریخی تا چه تاریخی؟',
                'query' => 'دنبال چه چیزی بگردم؟',
            ],
            // replies that drop the pending question
            'cancel_words' => ['بیخیال', 'بی خیال', 'ولش کن', 'لغو', 'کنسل', 'نمیخوام', 'نمی خوام', 'cancel', 'never mind', 'forget it'],
        ],
        // The standing briefing: what every agent run is given about the system, bounded so a large company stays affordable.
        // A section that is longer shows its first rows and says how many more there are (`total`, `truncated`).
        'briefing' => [
            'operations' => 150,
            'projects' => 60,
            'resources' => 150,
            'users' => 150,
            'members' => 100,
            'suggestions' => 40,
            'agents' => 30,
        ],
        // an action that needs the owner's approval waits this long for it
        'approval_ttl_hours' => 24,
        // the semantic layer: below `resolve_min` an unknown intent the AI wrote is not guessed; the best intent must also
        // beat the second best by `resolve_margin`. `hint_min` is the least similarity for an intent to be suggested.
        'semantic' => ['resolve_min' => 0.3, 'resolve_margin' => 0.05, 'hint_min' => 0.12, 'hints' => 4, 'search_min' => 0.12],
        // the same thresholds when an embedding model is blended in (its scores are on another scale)
        'semantic_embedding' => ['resolve_min' => 0.4, 'resolve_margin' => 0.03, 'hint_min' => 0.3, 'search_min' => 0.3],

        // What the AI is told about people and rights, so that it proposes only what the person may do and explains why not.
        'guide' => [
            'roles' => [
                'OWNER' => 'مالک: به همه‌ی دپارتمان‌ها دسترسی کامل دارد. تنظیمات سامانه، کاربران و نقش‌ها، دسترسی‌های دپارتمان‌ها، ایجنت‌ها، اختیارات دستیار و تأیید درخواست‌های پرریسک مدیرها فقط با اوست. درخواست خود مالک را خودش تأیید می‌کند.',
                'MANAGER' => 'مدیر: عملیات‌ها، پروژه‌ها، تخصیص، برنامه‌ریزی و تصمیم درباره‌ی پیشنهادهای دپارتمان‌ها را مدیریت می‌کند. به دپارتمان تولید و به دپارتمان‌هایی که عضو آن‌هاست دسترسی دارد، نه به منابع انسانی. عملیات پرریسکش باید مالک تأیید کند.',
                'USER_1' => 'مجری ۱: کاربر اجرایی. فقط وظایف تخصیص‌یافته به خودش را انجام می‌دهد و از دپارتمان‌هایی که عضو آن‌هاست (با دسترسی مشاهده) می‌خواند. به عملیات و پروژه‌های تولید دسترسی ندارد.',
                'USER_2' => 'مجری ۲: مانند مجری ۱.',
                'USER_3' => 'مجری ۳: مانند مجری ۱.',
                'AGENT' => 'ایجنت: کاربر مجازی که فقط می‌خواند و گزارش می‌دهد؛ هرگز وارد نمی‌شود، عملیات انجام نمی‌دهد و از سازنده‌اش بیشتر نمی‌بیند.',
            ],
            'department_roles' => [
                'HEAD' => 'سرپرست دپارتمان: به‌طور پیش‌فرض می‌تواند پنل را ببیند، منابع دپارتمان را به‌روز کند و پیشنهاد به مدیر بدهد. اگر مدیر باشد، داشبورد مدیر را هم در همان پنل دارد.',
                'MEMBER' => 'عضو دپارتمان: به‌طور پیش‌فرض می‌تواند پنل را ببیند و پیشنهاد بدهد؛ منابع را فقط با دسترسی جداگانه به‌روز می‌کند.',
            ],
            'permissions' => [
                'PANEL_VIEW' => 'دیدن پنل دپارتمان (بدون آن دپارتمان برای فرد وجود ندارد).',
                'RESOURCES_MANAGE' => 'ویرایش و تأیید به‌روز بودن منابع دپارتمان.',
                'SUGGESTIONS_SUBMIT' => 'ارسال پیشنهاد به مدیر.',
            ],
            'approval' => [
                'DENY' => 'مالک این عملیات را غیرمجاز کرده؛ در allowed_actions نمی‌آید.',
                'AUTO' => 'فقط ریسک کم: بی‌درنگ اجرا می‌شود.',
                'SELF' => 'درخواست‌دهنده باید در صفحه تأیید کند.',
                'OWNER' => 'مالک باید پیش از اجرا تأیید کند (اگر خود مالک بخواهد، تأیید خودش کافی است).',
            ],
        ],

        'kinds' => [
            'production' => [
                'purpose' => 'برنامه‌ریزی و اجرای کار: پروژه‌ها و اولویت‌ها، عملیات‌ها و وضعیتشان، تخصیص عملیات به مجریان، زمان‌بندی، گزارش و تاریخچه‌ی ورود اطلاعات.',
                'summary' => 'عملیات و پروژه‌های تولید با وضعیت‌های NOT_READY / READY / ASSIGNED / IN_PROGRESS / BLOCKED / COMPLETED / CANCELLED',
                'handler' => \App\AI\Departments\ProductionHandler::class,
                // who may read this department's data (capability) and who may change it (manage); see AppUser::allows()
                'capability' => 'production.view',
                'manage' => 'production.manage',
                'intents' => [
                    // ---- individual work and performance: owner only (capability performance.view). For work reporting, never a ranking.
                    'list_by_user' => [
                        'description' => 'عملیات‌های یک کاربر (مجری) به تفکیک وضعیت؛ برای «فلان کاربر الان چه کاری دارد». فقط مالک.',
                        'keywords' => 'کاربر کارکنان مجری چه کاری دارد عملیات‌های او کار فلان user1 user2 user3 assigned operations of user',
                        'returns' => 'حداکثر ۵۰ ردیف با id، name، project، resource، status، progress_percent، planned_end',
                        'calls' => 'Operation (assigned_user_id)',
                        'capability' => 'performance.view',
                        'params' => [
                            'user_id' => 'required|string|max:64',
                            'status' => 'nullable|in:NOT_READY,READY,ASSIGNED,IN_PROGRESS,BLOCKED,COMPLETED,CANCELLED',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'user_work_summary' => [
                        'description' => 'کارنامهٔ کاری یک کاربر: تعداد به تفکیک وضعیت، تعداد تکمیل‌شده، ساعت برنامه‌ریزی‌شدهٔ کارهای تکمیل‌شده، فاصلهٔ زمانی بین شروع و تکمیل (نه ساعت کار) و آخرین تکمیل. فقط مالک.',
                        'keywords' => 'کارنامه عملکرد کارکرد چه کرده کار انجام شده تکمیل خلاصه کار فلان user1 performance work summary what does user do',
                        'returns' => 'user_id، name، by_status، completed، planned_hours_completed، elapsed_hours_completed، elapsed_samples، last_completed، note',
                        'calls' => 'Operation و زمان‌های started_at / completed_at',
                        'capability' => 'performance.view',
                        'params' => ['user_id' => 'required|string|max:64'],
                    ],
                    'user_history' => [
                        'description' => 'تاریخچهٔ کاری یک کاربر: واگذاری‌ها و گزارش‌های عملیات‌هایش با زمان، جدیدترین اول. فقط مالک.',
                        'keywords' => 'تاریخچه سابقه گذشته کار قبلی گزارش‌ها زمانبندی history timeline past reports of user',
                        'returns' => 'حداکثر ۵۰ رویداد: at، operation، name، event (ASSIGNED یا نوع گزارش)، progress، note',
                        'calls' => 'Operation (assigned_at) و OperationReport',
                        'capability' => 'performance.view',
                        'params' => [
                            'user_id' => 'required|string|max:64',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'late_operations' => [
                        'description' => 'عملیات‌های باز که پایان برنامه‌ریزی‌شده‌شان از موعد پروژه گذشته یا از امروز عقب افتاده است، با تعداد روز تأخیر',
                        'keywords' => 'دیر عقب افتاده تأخیر موعد دیرتر از موعد گذشته سررسید late delayed overdue behind schedule which project is late',
                        'returns' => 'حداکثر ۵۰ ردیف با id، name، project، status، planned_end، project_due، delay_days (بزرگ‌ترین تأخیر اول)',
                        'calls' => 'Operation و Project.due_date',
                        'params' => ['limit' => 'nullable|integer|min:1|max:50'],
                    ],
                    'resource_load' => [
                        'description' => 'بار هر منبع: عملیات‌های باز، ظرفیت و ساعت برنامه‌ریزی‌شدهٔ باز. با resource_id فقط یک منبع.',
                        'keywords' => 'بار زیر بار شلوغ منبع ظرفیت دستگاه کدام منبع load busy overloaded capacity machine',
                        'returns' => 'id، name، capacity، status، open_operations، planned_hours_open',
                        'calls' => 'WorkResource و Operation باز',
                        'params' => [
                            'resource_id' => 'nullable|string|max:64',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'schedule_conflicts' => [
                        'description' => 'منابعی که در یک لحظه بیشتر از ظرفیتشان عملیات برنامه‌ریزی‌شده دارند (تداخل زمانی)',
                        'keywords' => 'تداخل هم‌زمان همپوشانی برخورد دوبار رزرو conflict overlap clash double booked over capacity',
                        'returns' => 'حداکثر ۵۰ منبع با resource، capacity، max_concurrent، operations (شناسه‌های درگیر)',
                        'calls' => 'Operation با planned_start / planned_end و WorkResource',
                        'params' => [],
                    ],
                    'project_detail' => [
                        'description' => 'جزئیات یک پروژه با شناسهٔ دقیق: پیشرفت، عملیات‌ها به تفکیک وضعیت، موعد و تعداد عملیات باز دیرتر از موعد',
                        'keywords' => 'پروژه جزئیات پیشرفت موعد تأخیر project detail progress due late',
                        'returns' => 'found، id، name، priority، status، due_date، operations، by_status، progress_percent، late_open_operations',
                        'calls' => 'Project و Operation',
                        'params' => ['project_id' => 'required|string|max:64'],
                    ],
                    'throughput' => [
                        'description' => 'تعداد عملیات تکمیل‌شده در یک بازهٔ تاریخی (از تاریخ تا تاریخ، هر دو روز شامل)',
                        'keywords' => 'تکمیل شده بازه خروجی این هفته این ماه تولید throughput completed period output',
                        'returns' => 'from، to، count',
                        'calls' => 'Operation (completed_at)',
                        'params' => [
                            'from' => 'required|date',
                            'to' => 'required|date',
                        ],
                    ],
                    'forecast' => [
                        'description' => 'پیش‌بینی زمان از برنامهٔ زمان‌بندی فعلی: شروع و پایان برنامه‌ریزی‌شدهٔ یک عملیات، پایان یک پروژه، یا پایان همهٔ پروژه‌ها. برای «کی تموم می‌شه؟»، «کی به دستم می‌رسه؟»، «چه زمانی خوبه؟».',
                        'keywords' => 'کی تموم میشه زمان تحویل پیش بینی چه زمانی کی میرسه پایان برنامه زمانبندی forecast when finish deliver eta schedule',
                        'returns' => 'operation (planned_start، planned_end، status، resource، assigned_to) یا project (finish_at، due_date، late_hours) یا projects (همه) و plan_finish_at',
                        'calls' => 'PlanService::plan (همان الگوریتم زمان‌بندی صفحهٔ برنامه)',
                        'params' => [
                            'operation_id' => 'nullable|string|max:64',
                            'project_id' => 'nullable|string|max:64',
                        ],
                    ],
                    'status_summary' => [
                        'description' => 'خلاصه‌ی وضعیت تولید: تعداد عملیات‌ها در هر وضعیت و تعداد پروژه‌ها',
                        'keywords' => 'وضعیت تولید چخبر چه خبر خلاصه آمار گزارش اوضاع تعداد عملیات‌ها status summary overview report how is production',
                        'returns' => 'operations_by_status (شمار هر وضعیت)، projects (تعداد پروژه‌ها)',
                        'calls' => 'Operation و Project (شمارش)',
                        'params' => [],
                    ],
                    'list_by_status' => [
                        'description' => 'فهرست عملیات‌هایی که در یک وضعیت مشخص‌اند (آماده، مسدود، در حال انجام، تخصیص‌یافته، تکمیل‌شده، لغوشده)',
                        'keywords' => 'فهرست لیست عملیات آماده مسدود بلاک در حال انجام تخصیص داده تکمیل لغو وضعیت کدام عملیات list operations blocked ready in progress assigned completed',
                        'returns' => 'حداکثر ۵۰ ردیف با id، name، project، resource، assigned_to، progress_percent، planned_end',
                        'calls' => 'Operation (فیلتر وضعیت)',
                        'params' => [
                            'status' => 'required|in:NOT_READY,READY,ASSIGNED,IN_PROGRESS,BLOCKED,COMPLETED,CANCELLED',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'project_summary' => [
                        'description' => 'فهرست پروژه‌ها با اولویت، وضعیت و موعد',
                        'keywords' => 'پروژه پروژه‌ها در حال اجرا فعال اولویت موعد تحویل project projects running active priority due',
                        'returns' => 'id، name، priority، status (ACTIVE یا ARCHIVED)، due_date',
                        'calls' => 'Project',
                        'params' => [],
                    ],
                    'operation_detail' => [
                        'description' => 'جزئیات یک عملیات با شناسه‌ی دقیقش (مثل OP-001)',
                        'keywords' => 'جزئیات عملیات شناسه آیتم شماره کد OP detail operation item id',
                        'returns' => 'found، id، name، status، project، resource، assigned_to، progress_percent، block_reason، planned_end',
                        'calls' => 'Operation (یک ردیف)',
                        'params' => ['operation_id' => 'required|string|max:64'],
                    ],
                    'search' => [
                        'description' => 'جست‌وجوی متنی در نام و توضیح عملیات‌ها و پروژه‌ها، وقتی شناسه‌ی دقیق معلوم نیست',
                        'keywords' => 'جستجو پیدا کن بگرد دنبال کدام عملیات مربوط به درباره search find look for about which',
                        'returns' => 'ردیف‌هایی با type (operation یا project)، id، name، status، score (شباهت)',
                        'calls' => 'Operation و Project + موتور جست‌وجوی معنایی',
                        'params' => ['query' => 'required|string|min:1|max:200', 'limit' => 'nullable|integer|min:1|max:50'],
                    ],
                ],
                'actions' => [
                    'import_plan' => [
                        'risk' => 'high',
                        'description' => 'فایل Excel که کاربر در گفتگو فرستاده (state.focus.upload_id) را از همان مسیر ورود اطلاعات وارد برنامه می‌کند. بدون project_id پروژهٔ تازه‌ای به نام فایل می‌سازد. only_valid: فقط ردیف‌های بی‌خطا. ردیف‌های کاربر از گفتگو وارد نمی‌شوند.',
                        'calls' => 'ImportService::processParsed (apply) از طریق ChatImportService',
                        'params' => [
                            'upload_id' => 'required|string|max:64|exists:chat_upload,id',
                            'project_id' => 'nullable|string|max:64|exists:project,id',
                            'new_project_name' => 'nullable|string|max:200',
                            'only_valid' => 'nullable|boolean',
                        ],
                    ],
                    'add_operation' => [
                        'risk' => 'medium',
                        'description' => 'یک عملیات تازه به یک پروژه اضافه می‌کند (از همان مسیر برنامه‌ریز، با همهٔ قواعد و ممیزی) و زمانش را از الگوریتم زمان‌بندی می‌گوید. بدون project_id به پروژهٔ با بالاترین اولویت می‌رود. after: شناسهٔ پیش‌نیازها با ویرگول. auto_assign: الگوریتم تخصیص، اولین مجری مناسب را انتخاب می‌کند.',
                        'calls' => 'PlanningService::submit، الگوریتم تخصیص و PlanService::plan',
                        'params' => [
                            'name' => 'required|string|max:200',
                            // left out: the best-fitting (else least-loaded) active resource and 1 hour (OperationDefaults)
                            'resource_id' => 'nullable|string|max:64|exists:work_resource,id',
                            'direct_hours' => 'nullable|numeric|min:0.01|max:10000',
                            'setup_hours' => 'nullable|numeric|min:0|max:10000',
                            'project_id' => 'nullable|string|max:64|exists:project,id',
                            'after' => 'nullable|string|max:500',
                            'user_id' => 'nullable|string|max:64|exists:app_user,id',
                            'auto_assign' => 'nullable|boolean',
                            'description' => 'nullable|string|max:1000',
                        ],
                    ],
                    'unblock_operation' => [
                        'risk' => 'medium',
                        'description' => 'عملیات مسدودشده (BLOCKED) را به وضعیت قبل از انسداد برمی‌گرداند و به مجری اطلاع می‌دهد. فقط وقتی عملیات BLOCKED است.',
                        'calls' => 'OperationService::unblock',
                        'params' => [
                            'operation_id' => 'required|string|max:64|exists:operation,id',
                            'note' => 'nullable|string|max:500',
                        ],
                    ],
                    'approve_completion' => [
                        'risk' => 'medium',
                        'description' => 'تکمیلی را که مجری گزارش کرده تأیید می‌کند (وقتی تنظیم «تأیید تکمیل توسط مدیر» روشن است). فقط برای عملیات تکمیل‌شده‌ی منتظر تأیید.',
                        'calls' => 'OperationService::approve',
                        'params' => [
                            'operation_id' => 'required|string|max:64|exists:operation,id',
                            'note' => 'nullable|string|max:500',
                        ],
                    ],
                    'cancel_operation' => [
                        'risk' => 'high',
                        'description' => 'عملیات را لغو می‌کند (وضعیت CANCELLED؛ حذف نیست و برگشت ندارد) و پیش‌نیازهای وابسته را دوباره ارزیابی می‌کند. دلیل اجباری است. فقط عملیات غیرپایانی.',
                        'calls' => 'OperationService::cancel',
                        'params' => [
                            'operation_id' => 'required|string|max:64|exists:operation,id',
                            'reason' => 'required|string|max:500',
                        ],
                    ],
                    'assign_operation' => [
                        'risk' => 'medium',
                        'description' => 'عملیات READY یا ASSIGNED را به یک مجری فعال (USER_1 تا USER_3) تخصیص می‌دهد و به او اطلاع می‌دهد. مالک، مدیر و ایجنت مجری نیستند. اگر تاریخی داده نشود، تاریخ‌های موجود عملیات حفظ می‌شود.',
                        'calls' => 'OperationService::assign',
                        'params' => [
                            'operation_id' => 'required|string|max:64|exists:operation,id',
                            'user_id' => 'required|string|max:64|exists:app_user,id',
                            'planned_start' => 'nullable|date',
                            'planned_end' => 'nullable|date',
                            'note' => 'nullable|string|max:300',
                        ],
                    ],
                    'unassign_operation' => [
                        'risk' => 'medium',
                        'description' => 'تخصیص یک عملیات تخصیص‌یافته (ASSIGNED) را برمی‌دارد و آن را دوباره به READY برمی‌گرداند؛ به مجری اطلاع می‌دهد.',
                        'calls' => 'OperationService::unassign',
                        'params' => [
                            'operation_id' => 'required|string|max:64|exists:operation,id',
                        ],
                    ],
                    'reschedule_operation' => [
                        'risk' => 'medium',
                        'description' => 'شروع و پایان برنامه‌ریزی‌شده‌ی یک عملیات تخصیص‌یافته را عوض می‌کند (اجبار مدیر). پایان نباید پیش از شروع باشد.',
                        'calls' => 'OperationService::reschedule',
                        'params' => [
                            'operation_id' => 'required|string|max:64|exists:operation,id',
                            'planned_start' => 'required|date',
                            'planned_end' => 'required|date|after_or_equal:planned_start',
                            'reason' => 'nullable|string|max:300',
                        ],
                    ],
                    'comment_on_operation' => [
                        'risk' => 'low',
                        'description' => 'یادداشتی روی عملیات می‌نویسد. وضعیت و تخصیص را تغییر نمی‌دهد.',
                        'calls' => 'OperationService::comment',
                        'params' => [
                            'operation_id' => 'required|string|max:64|exists:operation,id',
                            'note' => 'required|string|max:500',
                        ],
                    ],
                    'update_project' => [
                        'risk' => 'medium',
                        'description' => 'نام، موعد یا وضعیت (ACTIVE یا ARCHIVED) پروژه را عوض می‌کند. بایگانی فقط وقتی ممکن است که همه‌ی عملیات‌های پروژه تمام یا لغو شده باشند؛ فعال‌کردن دوباره، پروژه را در آخر اولویت‌ها می‌گذارد.',
                        'calls' => 'ProjectService::update',
                        'params' => [
                            'project_id' => 'required|string|max:64|exists:project,id',
                            'name' => 'nullable|string|max:200',
                            'due_date' => 'nullable|date',
                            'status' => 'nullable|in:ACTIVE,ARCHIVED',
                        ],
                    ],
                    ...$agentActions,
                ],
            ],
            'hr' => [
                'purpose' => 'افراد شرکت: حساب‌های کاربری و نقش‌ها، عضویت در دپارتمان‌ها، ایجنت‌های هوش مصنوعی و گزارش ممیزی.',
                'summary' => 'کاربران سامانه: تعداد به تفکیک نقش و فعال بودن، فهرست و جست‌وجوی کاربران (بدون اطلاعات تماس)',
                'handler' => \App\AI\Departments\HrHandler::class,
                'capability' => 'users.manage',
                'manage' => 'users.manage',
                'intents' => [
                    'user_detail' => [
                        'description' => 'مشخصات یک کاربر با شناسه: نقش، فعال بودن و عضویت در دپارتمان‌ها با نقش هر کدام. هرگز شماره تماس یا رمز.',
                        'keywords' => 'کاربر مشخصات نقش فعال غیرفعال عضو دپارتمان چه نقشی user detail role member active',
                        'returns' => 'found، id، name، role، active، departments (name، role)',
                        'calls' => 'AppUser و DepartmentMember',
                        'params' => ['user_id' => 'required|string|max:64'],
                    ],
                    'department_members' => [
                        'description' => 'اعضای یک دپارتمان با نقش دپارتمانی‌شان، با شناسهٔ دپارتمان',
                        'keywords' => 'اعضا عضو دپارتمان تیم اعضای members staff department who is in',
                        'returns' => 'حداکثر ۵۰ ردیف با id، name، role (بدون تماس و رمز)',
                        'calls' => 'DepartmentMember و AppUser',
                        'params' => [
                            'department_id' => 'required|integer|min:1',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'users_summary' => [
                        'description' => 'تعداد کاربران به تفکیک نقش و تعداد کاربران فعال',
                        'keywords' => 'آمار گزارش تعداد کاربران کارمندان کارمند پرسنل نیروی انسانی نقش‌ها فعال خلاصه چند نفر چخبر summary users count staff employees headcount report',
                        'returns' => 'by_role (شمار هر نقش)، total، active',
                        'calls' => 'AppUser (شمارش)',
                        'params' => [],
                    ],
                    'list_users' => [
                        'description' => 'فهرست کاربران با نقش و وضعیت فعال بودن، با امکان فیلتر نقش',
                        'keywords' => 'فهرست لیست کاربران اسامی کارمندان کارمند پرسنل افراد چه کسانی نقش مدیر list users staff names people who',
                        'returns' => 'حداکثر ۵۰ ردیف با id، name، role، active (هرگز رمز، تماس یا شناسه‌ی پیام‌رسان)',
                        'calls' => 'AppUser',
                        'params' => [
                            'role' => 'nullable|in:OWNER,MANAGER,USER_1,USER_2,USER_3',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'search_users' => [
                        'description' => 'پیدا کردن کاربر با نام یا شناسه',
                        'keywords' => 'جستجو پیدا کن کاربر نام شناسه کیست search find user named who is',
                        'returns' => 'ردیف‌هایی با id، name، role، active، score',
                        'calls' => 'AppUser + موتور جست‌وجوی معنایی',
                        'params' => ['query' => 'required|string|min:1|max:200', 'limit' => 'nullable|integer|min:1|max:50'],
                    ],
                ],
                'actions' => [
                    'set_user_active' => [
                        'risk' => 'high',
                        'description' => 'حساب یک کاربر را فعال یا غیرفعال می‌کند. غیرفعال‌سازی کاربری که وظیفه‌ی باز دارد رد می‌شود؛ حساب مالک غیرفعال نمی‌شود؛ ایجنت‌ها از صفحه‌ی ایجنت‌ها مدیریت می‌شوند.',
                        'calls' => 'UserService::update',
                        'params' => [
                            'user_id' => 'required|string|max:64|exists:app_user,id',
                            'active' => 'required|boolean',
                        ],
                    ],
                    'set_user_role' => [
                        'risk' => 'high',
                        'description' => 'نقش یک کاربر را به MANAGER یا USER_1 تا USER_3 تغییر می‌دهد. نقش مالک و ایجنت از این راه داده نمی‌شود و نقش مالک عوض نمی‌شود.',
                        'calls' => 'UserService::update',
                        'params' => [
                            'user_id' => 'required|string|max:64|exists:app_user,id',
                            'role' => 'required|in:MANAGER,USER_1,USER_2,USER_3',
                        ],
                    ],
                    'add_department_member' => [
                        'risk' => 'high',
                        'description' => 'کاربری را با نقش HEAD یا MEMBER به یک دپارتمان اضافه می‌کند (یا نقشش را عوض می‌کند). مالک و ایجنت عضو نمی‌شوند؛ کاربر غیرفعال اضافه نمی‌شود.',
                        'calls' => 'DepartmentService::setMembers',
                        'params' => [
                            'department_id' => 'required|integer|exists:department,id',
                            'user_id' => 'required|string|max:64|exists:app_user,id',
                            'role' => 'required|in:HEAD,MEMBER',
                        ],
                    ],
                    'remove_department_member' => [
                        'risk' => 'high',
                        'description' => 'عضویت یک کاربر را در یک دپارتمان برمی‌دارد؛ کاربر حساب خود را حفظ می‌کند و فقط دسترسی‌اش به آن دپارتمان قطع می‌شود.',
                        'calls' => 'DepartmentService::setMembers',
                        'params' => [
                            'department_id' => 'required|integer|exists:department,id',
                            'user_id' => 'required|string|max:64|exists:app_user,id',
                        ],
                    ],
                    ...$agentActions,
                ],
            ],
            'general' => [
                'purpose' => 'کار هر دپارتمان دیگری که مالک ساخته: اعضا و نقش‌هایشان، منابعی که دپارتمان به‌روز نگه می‌دارد، و پیشنهادهایش به مدیر.',
                'summary' => 'اعضا، منابعی که دپارتمان به‌روز نگه می‌دارد و پیشنهادهایش به مدیر',
                'handler' => \App\AI\Departments\GeneralDepartmentHandler::class,
                // null: every person (an agent only through its scope); the actions below name their own capability
                'capability' => null,
                'manage' => null,
                'intents' => [
                    'audit_recent' => [
                        'description' => 'آخرین رویدادهای ممیزی (چه کسی، چه زمانی، چه تغییری)، می‌تواند به یک موجودیت یا یک کاربر محدود شود. فقط مالک؛ برای پرسش‌هایی مثل «آخرین تغییرات روی OP-1».',
                        'keywords' => 'ممیزی تغییرات گزارش ممیزی تاریخچه تغییر کی چه زمانی چه کسی audit log changes history who when',
                        'returns' => 'حداکثر ۵۰ رویداد: at، actor، action، entity، change، reason',
                        'calls' => 'AuditEvent',
                        'capability' => 'performance.view',
                        'params' => [
                            'entity_type' => 'nullable|string|max:40',
                            'entity_id' => 'nullable|string|max:64',
                            'actor_id' => 'nullable|string|max:64',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'overview' => [
                        'description' => 'نمای کلی دپارتمان: تعداد اعضا، منابع، منابع به‌روز‌نشده و پیشنهادهای در انتظار، و فهرست افراد',
                        'keywords' => 'چخبر چه خبر وضعیت خلاصه نمای کلی آمار اعضا افراد گزارش overview summary members status',
                        'returns' => 'name، members، resources، stale_resources، pending_suggestions، review_days، people (نام و نقش)',
                        'calls' => 'DepartmentService::detail',
                        'params' => [],
                    ],
                    'list_resources' => [
                        'description' => 'فهرست منابع دپارتمان با ظرفیت، وضعیت و زمان آخرین تأیید به‌روز بودن',
                        'keywords' => 'منابع منبع ماشین تجهیزات ظرفیت به‌روز تأیید فهرست لیست resources equipment capacity stale confirmed',
                        'returns' => 'id، name، capacity، status، confirmed_at، stale (از مهلت تأیید گذشته؟)',
                        'calls' => 'DepartmentService::detail',
                        'params' => ['limit' => 'nullable|integer|min:1|max:50'],
                    ],
                    'list_suggestions' => [
                        'description' => 'پیشنهادهای دپارتمان به مدیر با وضعیت (در انتظار، پذیرفته، رد شده)',
                        'keywords' => 'پیشنهاد پیشنهادها پیشنهادات درخواست در انتظار پذیرفته رد شده suggestions proposals pending accepted rejected',
                        'returns' => 'id، title، status، author، created_at، manager_note',
                        'calls' => 'DepartmentService::detail',
                        'params' => [
                            'status' => 'nullable|in:PENDING,ACCEPTED,REJECTED',
                            'limit' => 'nullable|integer|min:1|max:50',
                        ],
                    ],
                    'search' => [
                        'description' => 'جست‌وجوی متنی در منابع و پیشنهادهای دپارتمان',
                        'keywords' => 'جستجو پیدا کن بگرد دنبال کدام مربوط به درباره search find look for about which',
                        'returns' => 'ردیف‌هایی با type (resource یا suggestion)، id، name یا title، status، score',
                        'calls' => 'DepartmentService::detail + موتور جست‌وجوی معنایی',
                        'params' => ['query' => 'required|string|min:1|max:200', 'limit' => 'nullable|integer|min:1|max:50'],
                    ],
                ],
                'actions' => [
                    'confirm_resource' => [
                        'risk' => 'medium', 'permission' => 'RESOURCES_MANAGE',
                        'description' => 'تأیید می‌کند که یک منبع دپارتمان به‌روز است (زمان تأیید را همین حالا ثبت می‌کند) بدون تغییر دادن مشخصاتش. دپارتمان باید فعال و منبع از آنِ همان دپارتمان باشد.',
                        'calls' => 'DepartmentService::updateResource (بدون تغییر فیلد)',
                        'params' => [
                            'resource_id' => 'required|string|max:64|exists:work_resource,id',
                        ],
                    ],
                    'update_resource' => [
                        'risk' => 'medium', 'permission' => 'RESOURCES_MANAGE',
                        'description' => 'نام، ظرفیت، وضعیت (ACTIVE یا INACTIVE) یا توضیح یک منبع دپارتمان را تغییر می‌دهد و در همان حال آن را «به‌روز» تأیید می‌کند. ظرفیت روی برنامه‌ریزی همه‌ی پروژه‌ها اثر می‌گذارد.',
                        'calls' => 'DepartmentService::updateResource',
                        'params' => [
                            'resource_id' => 'required|string|max:64|exists:work_resource,id',
                            'name' => 'nullable|string|max:200',
                            'capacity' => 'nullable|integer|min:1|max:100000',
                            'status' => 'nullable|in:ACTIVE,INACTIVE',
                            'description' => 'nullable|string|max:1000',
                        ],
                    ],
                    'submit_suggestion' => [
                        'risk' => 'low', 'permission' => 'SUGGESTIONS_SUBMIT',
                        'description' => 'پیشنهادی از طرف دپارتمان برای مدیر ثبت می‌کند؛ به مدیرها اعلان می‌رسد و مدیر در داشبوردش تصمیم می‌گیرد. منبع مرتبط اختیاری و باید از آنِ همین دپارتمان باشد.',
                        'calls' => 'DepartmentService::submitSuggestion',
                        'params' => [
                            'title' => 'required|string|max:200',
                            'body' => 'required|string|max:2000',
                            'resource_id' => 'nullable|string|max:64',
                        ],
                    ],
                    'decide_suggestion' => [
                        'risk' => 'medium', 'capability' => 'production.manage',
                        'description' => 'پیشنهاد در انتظارِ یک دپارتمان را می‌پذیرد (ACCEPTED) یا رد می‌کند (REJECTED) و به صاحب پیشنهاد اعلان می‌دهد. فقط مالک و مدیر؛ فقط پیشنهادی که هنوز تصمیم نگرفته‌اند.',
                        'calls' => 'DepartmentService::decide',
                        'params' => [
                            'suggestion_id' => 'required|integer|exists:department_suggestion,id',
                            'decision' => 'required|in:ACCEPTED,REJECTED',
                            'note' => 'nullable|string|max:500',
                        ],
                    ],
                ],
            ],
        ],
    ],

    /*
     * Working time for the schedule. The planner counts hours of work; dates skip nights, days off and holidays.
     * Default: Saturday to Wednesday 08:00-16:00, Thursday 08:00-12:00, Friday off, Tehran time. AISO_WORK_CALENDAR=false
     * gives continuous time (24/7), as before. Holidays: dates (Y-m-d, local) in AISO_HOLIDAYS, comma separated.
     */
    'calendar' => [
        'enabled' => (bool) env('AISO_WORK_CALENDAR', true),
        'timezone' => env('AISO_WORK_TIMEZONE', 'Asia/Tehran'),
        'days' => [
            'sat' => [['08:00', '16:00']],
            'sun' => [['08:00', '16:00']],
            'mon' => [['08:00', '16:00']],
            'tue' => [['08:00', '16:00']],
            'wed' => [['08:00', '16:00']],
            'thu' => [['08:00', '12:00']],
        ],
        'holidays' => array_values(array_filter(array_map('trim', explode(',', (string) env('AISO_HOLIDAYS', ''))))),
    ],

    // Planning live work: blocked work is planned to wait this many working hours (when the block is lifted is unknown)
    'scheduling' => [
        'blocked_release_hours' => (float) env('AISO_BLOCKED_RELEASE_HOURS', 8),
    ],

    'departments' => [
        // A resource a department keeps up to date must have been confirmed within this many days; older (or never
        // confirmed) resources are flagged as stale in the department panel and on the manager dashboard.
        'review_days' => (int) env('AISO_RESOURCE_REVIEW_DAYS', 30),
    ],

    'vendor' => [
        // The software vendor's console. Only HASHES are stored: the user name as HMAC-SHA256 (keyed with the salt) and the
        // password as a bcrypt hash. Create them with "php artisan aiso:vendor-credentials". Leaving them empty switches
        // the console off completely (its API then answers 404, as if it did not exist).
        'salt' => env('AISO_VENDOR_SALT', ''),
        'user_hash' => env('AISO_VENDOR_USER_HASH', ''),
        'password_hash' => env('AISO_VENDOR_PASSWORD_HASH', ''),
        // A vendor session ends after this many idle minutes.
        'idle_minutes' => (int) env('AISO_VENDOR_IDLE_MINUTES', 30),
        // Extra factor 1, "something this browser has": SHA-256 of a secret token that must be present in the cookie named
        // VendorAuth::COOKIE. Without the cookie the vendor sign-in behaves exactly like a wrong password. Empty = not required.
        'token_hash' => env('AISO_VENDOR_TOKEN_HASH', ''),
        // Extra factor 2, "something you know/hold": base32 secret of an authenticator app (RFC 6238 TOTP). Empty = not required.
        'totp_secret' => env('AISO_VENDOR_TOTP_SECRET', ''),
    ],

    'bale' => [
        // Fallback token of the bot created with @BotFather. The owner normally sets it in Settings (stored encrypted, see BaleConfig).
        'token' => env('BALE_BOT_TOKEN', ''),
        // Bot API base URL. Methods are called as {base}/bot{token}/{method}.
        'api_url' => env('BALE_API_URL', 'https://tapi.bale.ai'),
    ],
];
