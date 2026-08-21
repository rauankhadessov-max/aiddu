<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>AI DDU Assistant</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at top right, #dbeafe 0, transparent 34%),
                linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            color: #0f172a;
            min-height: 100vh;
        }

        .container {
            width: min(1180px, calc(100% - 40px));
            margin: 0 auto;
        }

        header {
            padding: 24px 0;
        }

        nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 21px;
            font-weight: 800;
        }

        .logo-mark {
            display: grid;
            place-items: center;
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: #2563eb;
            color: white;
            font-size: 16px;
            box-shadow: 0 12px 28px rgba(37, 99, 235, 0.25);
        }

        .login-link {
            color: #334155;
            text-decoration: none;
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            align-items: center;
            gap: 60px;
            padding: 80px 0 110px;
        }

        .badge {
            display: inline-block;
            margin-bottom: 22px;
            padding: 9px 14px;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            color: #1d4ed8;
            font-size: 14px;
            font-weight: 700;
        }

        h1 {
            max-width: 760px;
            font-size: clamp(46px, 7vw, 82px);
            line-height: 0.98;
            letter-spacing: -4px;
        }

        h1 span {
            color: #2563eb;
        }

        .subtitle {
            max-width: 650px;
            margin-top: 28px;
            color: #475569;
            font-size: 20px;
            line-height: 1.65;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 34px;
        }

        .button {
            display: inline-block;
            padding: 15px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 800;
        }

        .button-primary {
            background: #2563eb;
            color: white;
            box-shadow: 0 14px 30px rgba(37, 99, 235, 0.25);
        }

        .button-secondary {
            border: 1px solid #cbd5e1;
            background: white;
            color: #0f172a;
        }

        .panel {
            padding: 28px;
            border: 1px solid rgba(203, 213, 225, 0.9);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.82);
            box-shadow: 0 30px 70px rgba(15, 23, 42, 0.12);
            backdrop-filter: blur(12px);
        }

        .panel h2 {
            margin-bottom: 20px;
            font-size: 22px;
        }

        .feature {
            display: flex;
            gap: 14px;
            padding: 16px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .feature:last-child {
            border-bottom: 0;
        }

        .check {
            display: grid;
            place-items: center;
            flex: 0 0 32px;
            height: 32px;
            border-radius: 10px;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 900;
        }

        .feature strong {
            display: block;
            margin-bottom: 5px;
        }

        .feature p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }

        .capabilities {
            padding: 85px 0;
            background: white;
        }

        .section-title {
            max-width: 720px;
            margin-bottom: 42px;
        }

        .section-title h2 {
            font-size: 40px;
            letter-spacing: -1.5px;
        }

        .section-title p {
            margin-top: 14px;
            color: #64748b;
            font-size: 18px;
            line-height: 1.6;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .card {
            min-height: 190px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            background: #f8fafc;
        }

        .card-number {
            margin-bottom: 20px;
            color: #2563eb;
            font-size: 13px;
            font-weight: 900;
        }

        .card h3 {
            margin-bottom: 10px;
            font-size: 19px;
        }

        .card p {
            color: #64748b;
            line-height: 1.6;
        }

        footer {
            padding: 30px 0;
            border-top: 1px solid #e2e8f0;
            background: white;
            color: #64748b;
            text-align: center;
            font-size: 14px;
        }

        @media (max-width: 850px) {
            .hero {
                grid-template-columns: 1fr;
                padding-top: 45px;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            h1 {
                letter-spacing: -2px;
            }
        }
    </style>
</head>

<body>
<header>
    <div class="container">
        <nav>
            <div class="logo">
                <div class="logo-mark">AI</div>
                <span>AI DDU Assistant</span>
            </div>

            <a class="login-link" href="{{ route('login') }}">Войти</a>
        </nav>
    </div>
</header>

<main>
    <section class="container hero">
        <div>
            <div class="badge">LegalTech · Долевое строительство · Казахстан</div>

            <h1>
                Искусственный интеллект для
                <span>нормотворческой работы</span>
            </h1>

            <p class="subtitle">
                Проверка проектов подзаконных НПА, сопоставление русской и
                казахской версий, поиск юридических коллизий и подготовка
                сравнительных таблиц.
            </p>

            <div class="actions">
                <a class="button button-primary" href="{{ auth()->check() ? route('dashboard') : route('login') }}">Начать работу</a>
                <a class="button button-secondary" href="#capabilities">
                    Возможности системы
                </a>
            </div>
        </div>

        <div class="panel">
            <h2>Основные задачи MVP</h2>

            <div class="feature">
                <div class="check">✓</div>
                <div>
                    <strong>Проверка соответствия Закону</strong>
                    <p>
                        Анализ проекта подзаконного НПА и выявление возможных
                        противоречий нормам закона.
                    </p>
                </div>
            </div>

            <div class="feature">
                <div class="check">✓</div>
                <div>
                    <strong>Сопоставление RU и KZ</strong>
                    <p>
                        Выявление смысловых расхождений между русской и
                        казахской редакциями.
                    </p>
                </div>
            </div>

            <div class="feature">
                <div class="check">✓</div>
                <div>
                    <strong>Формирование документов</strong>
                    <p>
                        Подготовка сравнительной таблицы, замечаний и проекта
                        приказа.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="capabilities" id="capabilities">
        <div class="container">
            <div class="section-title">
                <h2>Возможности AI DDU</h2>
                <p>
                    Первый MVP будет сосредоточен на задачах, которые требуют
                    значительных трудозатрат при ручном юридическом анализе.
                </p>
            </div>

            <div class="cards">
                <article class="card">
                    <div class="card-number">01</div>
                    <h3>Анализ НПА</h3>
                    <p>
                        Сопоставление положений проекта нормативного акта
                        с нормами закона и выявление правовых рисков.
                    </p>
                </article>

                <article class="card">
                    <div class="card-number">02</div>
                    <h3>Сравнение редакций</h3>
                    <p>
                        Поиск изменений, исключенных положений и новых норм
                        между двумя редакциями документа.
                    </p>
                </article>

                <article class="card">
                    <div class="card-number">03</div>
                    <h3>Проверка перевода</h3>
                    <p>
                        Смысловое сопоставление казахской и русской версий
                        нормативного правового акта.
                    </p>
                </article>

                <article class="card">
                    <div class="card-number">04</div>
                    <h3>Юридические коллизии</h3>
                    <p>
                        Обнаружение противоречий, дублирования норм и
                        некорректных ссылок на статьи законодательства.
                    </p>
                </article>

                <article class="card">
                    <div class="card-number">05</div>
                    <h3>Сравнительная таблица</h3>
                    <p>
                        Формирование структуры действующей и предлагаемой
                        редакций с обоснованием поправок.
                    </p>
                </article>

                <article class="card">
                    <div class="card-number">06</div>
                    <h3>Проект приказа</h3>
                    <p>
                        Подготовка первоначального проекта приказа на основе
                        принятых законодательных изменений.
                    </p>
                </article>
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="container">
        © 2026 AI DDU Assistant · Интеллектуальная система правового анализа
    </div>
</footer>
</body>
</html>
