<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Youth Mental Health</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #334155;
            padding-top: 5rem;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 10% 10%, rgba(59, 130, 246, 0.08) 0%, rgba(6, 182, 212, 0.05) 40%, transparent 70%);
            pointer-events: none;
            z-index: -1;
            animation: subtleGlow 10s infinite alternate;
        }

        @keyframes subtleGlow {
            from {
                transform: scale(1);
                opacity: 0.8;
            }

            to {
                transform: scale(1.05);
                opacity: 1;
            }
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .card {
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05), 0 1px 3px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.15);
        }

        .nav-button.active {
            background-color: #3B82F6;
            color: white;
        }

        .login-button {
            background-color: #06B6D4;
            color: white;
        }

        .login-button:hover {
            background-color: #0891B2;
        }

        .fixed-nav {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            background-color: rgba(248, 250, 252, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body class="antialiased">
    <nav id="main-nav" class="fixed-nav flex justify-center py-4">
        <div class="flex space-x-4 bg-white bg-opacity-80 p-2 rounded-full shadow-lg border border-blue-200">
            <button id="data-collection-nav-button"
                class="nav-button px-4 py-2 rounded-full text-sm font-medium text-slate-600 hover:bg-blue-500 hover:text-white transition-colors duration-200">Data
                Collection</button>
            <button id="eda-nav-button"
                class="nav-button px-4 py-2 rounded-full text-sm font-medium text-slate-600 hover:bg-blue-500 hover:text-white transition-colors duration-200">EDA</button>
            <button id="model-selection-nav-button"
                class="nav-button px-4 py-2 rounded-full text-sm font-medium text-slate-600 hover:bg-blue-500 hover:text-white transition-colors duration-200">Model
                Selection</button>
            <button id="feature-importance-nav-button"
                class="nav-button px-4 py-2 rounded-full text-sm font-medium text-slate-600 hover:bg-blue-500 hover:text-white transition-colors duration-200">Feature
                Importance</button>

            <?php
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            ?>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="login.php"
                    class="login-button px-4 py-2 rounded-full text-sm font-medium text-white hover:bg-sky-600 transition-colors duration-200">Login</a>
            <?php else: ?>
                <a href=<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN') {
                    echo '"dashboard.php"';
                } else {
                    echo '"user_dashboard.php"';
                } ?> class="login-button px-4 py-2 rounded-full text-sm font-medium text-white hover:bg-sky-600 transition-colors duration-200">Dashboard</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        <main class="mt-8">
            <section id="project-overview-section"
                class="card mb-8 bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 border-0 shadow-xl">
                <div class="flex items-center mb-8">
                    <div
                        class="w-16 h-16 bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl flex items-center justify-center mr-6 shadow-2xl">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                            </path>
                        </svg>
                    </div>
                    <h1
                        class="text-4xl md:text-5xl font-bold bg-gradient-to-r from-blue-900 via-purple-800 to-indigo-900 bg-clip-text text-transparent">
                        Youth Mental Health AI Project</h1>
                </div>

                <div class="bg-white/70 backdrop-blur-sm rounded-2xl p-8 shadow-lg border border-blue-100">
                    <div class="flex items-center mb-6">
                        <div
                            class="w-10 h-10 bg-gradient-to-r from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center mr-4">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h2
                            class="text-2xl font-bold bg-gradient-to-r from-blue-800 to-indigo-800 bg-clip-text text-transparent">
                            Project Overview</h2>
                    </div>
                    <p class="text-slate-700 leading-relaxed mb-4 text-lg">
                        This project is dedicated to understanding and improving the mental well-being of young
                        individuals.
                        By applying AI and machine learning to comprehensive datasets, we aim to uncover key factors
                        that
                        influence youth mental health. Our goal is to develop a predictive model that can identify early
                        signs of mental health challenges, enabling timely and effective support.
                    </p>
                    <p class="text-slate-700 leading-relaxed">
                        The dataset contains a wide range of anonymized information, including demographics, lifestyle
                        habits, social factors, and self-reported mental health statuses. The primary objective is to
                        build
                        a robust predictive model to identify individuals who may be at risk, allowing for proactive
                        interventions from mental health professionals, educators, and support networks.
                    </p>
                </div>
            </section>

            <section id="data-collection-section"
                class="card mb-8 bg-gradient-to-br from-cyan-50 to-blue-50 border-l-4 border-cyan-500 shadow-lg">
                <div class="flex items-center mb-6">
                    <div
                        class="w-12 h-12 bg-gradient-to-r from-cyan-500 to-blue-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z">
                            </path>
                        </svg>
                    </div>
                    <h2
                        class="text-2xl font-bold bg-gradient-to-r from-cyan-800 to-blue-800 bg-clip-text text-transparent">
                        Data Collection & Preprocessing</h2>
                </div>

                <div class="bg-white/70 backdrop-blur-sm rounded-xl p-6 shadow-sm border border-cyan-100">
                    <p class="text-slate-700 leading-relaxed text-lg">
                        Our data collection focuses on key lifestyle and wellness indicators that influence youth mental
                        health. We gather information on variables such as Age, Hours of Screen Time, Hours of Sleep,
                        Daily
                        Study Hours, and Physical Activity. This data, combined with a Mental Clarity Score, is
                        ethically
                        sourced and anonymized. The raw data undergoes a rigorous preprocessing phase, which includes
                        cleaning, handling missing values, and transforming features to prepare it for machine learning
                        models. This ensures the quality and reliability of our analysis.
                    </p>
                </div>
            </section>

            <section id="eda-section"
                class="card mb-8 bg-gradient-to-br from-emerald-50 to-green-50 border-l-4 border-emerald-500 shadow-lg">
                <div class="flex items-center mb-6">
                    <div
                        class="w-12 h-12 bg-gradient-to-r from-emerald-500 to-green-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z">
                            </path>
                        </svg>
                    </div>
                    <h2
                        class="text-2xl font-bold bg-gradient-to-r from-emerald-800 to-green-800 bg-clip-text text-transparent">
                        Exploratory Data Analysis (EDA)</h2>
                </div>

                <div class="bg-white/70 backdrop-blur-sm rounded-xl p-6 shadow-sm border border-emerald-100">
                    <p class="text-slate-700 leading-relaxed text-lg">
                        Through EDA, we explore the dataset to uncover initial insights and correlations. Visualizations
                        like heatmaps, scatter plots, and distributions help us understand the complex relationships
                        between
                        different factors and mental health indicators. This step is vital for hypothesis generation and
                        feature engineering.
                    </p>
                </div>
            </section>

            <section id="model-selection-section"
                class="card mb-8 bg-gradient-to-br from-purple-50 to-indigo-50 border-l-4 border-purple-500 shadow-lg">
                <div class="flex items-center mb-6">
                    <div
                        class="w-12 h-12 bg-gradient-to-r from-purple-500 to-indigo-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                            </path>
                        </svg>
                    </div>
                    <h2
                        class="text-2xl font-bold bg-gradient-to-r from-purple-800 to-indigo-800 bg-clip-text text-transparent">
                        Model Selection</h2>
                </div>

                <div class="bg-white/70 backdrop-blur-sm rounded-xl p-6 shadow-sm border border-purple-100">
                    <p class="text-slate-700 leading-relaxed text-lg">
                        We evaluate a variety of machine learning models to determine the most accurate and reliable one
                        for
                        predicting mental health risks. Models are trained on the preprocessed data, and their
                        performance
                        is rigorously assessed using metrics such as accuracy, precision, recall, and F1-score to ensure
                        the
                        effectiveness of our predictive system.
                    </p>
                </div>
            </section>

            <section id="feature-importance-section"
                class="card mb-8 bg-gradient-to-br from-orange-50 to-red-50 border-l-4 border-orange-500 shadow-lg">
                <div class="flex items-center mb-6">
                    <div
                        class="w-12 h-12 bg-gradient-to-r from-orange-500 to-red-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                    </div>
                    <h2
                        class="text-2xl font-bold bg-gradient-to-r from-orange-800 to-red-800 bg-clip-text text-transparent">
                        Feature Importance</h2>
                </div>

                <div class="bg-white/70 backdrop-blur-sm rounded-xl p-6 shadow-sm border border-orange-100">
                    <p class="text-slate-700 leading-relaxed text-lg">
                        This analysis identifies the most influential factors affecting youth mental health. By
                        understanding which features have the greatest impact—be it academic pressure, social
                        connections,
                        or lifestyle choices—we can provide targeted recommendations for interventions and support
                        systems
                        that address the root causes of mental health issues.
                    </p>
                </div>
            </section>

            <section id="conclusion-section"
                class="card mb-8 bg-gradient-to-br from-teal-50 to-cyan-50 border-l-4 border-teal-500 shadow-lg">
                <div class="flex items-center mb-6">
                    <div
                        class="w-12 h-12 bg-gradient-to-r from-teal-500 to-cyan-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z">
                            </path>
                        </svg>
                    </div>
                    <h2
                        class="text-2xl font-bold bg-gradient-to-r from-teal-800 to-cyan-800 bg-clip-text text-transparent">
                        Conclusion</h2>
                </div>

                <div class="bg-white/70 backdrop-blur-sm rounded-xl p-6 shadow-sm border border-teal-100">
                    <p class="text-slate-700 leading-relaxed text-lg">
                        Our project harnesses the power of AI to create a proactive and supportive environment for youth
                        mental health. By identifying critical risk factors and providing data-driven insights, we aim
                        to
                        empower communities, educators, and healthcare providers to make a meaningful difference in the
                        lives of young people.
                    </p>
                </div>
            </section>

            <section id="contact-section"
                class="card mb-8 bg-gradient-to-br from-indigo-50 to-purple-50 border-l-4 border-indigo-500 shadow-lg">
                <div class="flex items-center mb-6">
                    <div
                        class="w-12 h-12 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                            </path>
                        </svg>
                    </div>
                    <h2
                        class="text-2xl font-bold bg-gradient-to-r from-indigo-800 to-purple-800 bg-clip-text text-transparent">
                        Get in Touch</h2>
                </div>

                <div class="bg-white/70 backdrop-blur-sm rounded-xl p-6 shadow-sm border border-indigo-100">
                    <p class="text-slate-700 leading-relaxed text-lg">
                        We welcome collaborations, questions, and feedback. If you'd like to learn more about our work
                        or
                        get involved, please reach out to our team at:
                        <a href="mailto:contact@youthmentalhealthai.org"
                            class="text-indigo-600 hover:text-indigo-800 font-semibold underline decoration-2 underline-offset-2 hover:decoration-indigo-800 transition-colors ml-1">contact@youthmentalhealthai.org</a>
                    </p>

                    <div
                        class="mt-6 p-4 bg-gradient-to-r from-indigo-100 to-purple-100 rounded-lg border border-indigo-200">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-indigo-500 rounded-full flex items-center justify-center">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div>
                                <div class="font-semibold text-indigo-800">Quick Response</div>
                                <div class="text-sm text-indigo-600">We typically respond within 24 hours</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>


        </main>
    </div>

    <script>

        function scrollToSection(sectionId) {
            const section = document.getElementById(sectionId);
            const navBar = document.getElementById('main-nav');

            if (section && navBar) {
                const navHeight = navBar.offsetHeight;
                const sectionTop = section.getBoundingClientRect().top + window.pageYOffset;

                window.scrollTo({
                    top: sectionTop - navHeight - 20,
                    behavior: 'smooth'
                });
            }
        }

        const dataCollectionButton = document.getElementById('data-collection-nav-button');
        const edaButton = document.getElementById('eda-nav-button');
        const modelSelectionButton = document.getElementById('model-selection-nav-button');
        const featureImportanceButton = document.getElementById('feature-importance-nav-button');

        if (dataCollectionButton) {
            dataCollectionButton.addEventListener('click', () => scrollToSection('data-collection-section'));
        }
        if (edaButton) {
            edaButton.addEventListener('click', () => scrollToSection('eda-section'));
        }
        if (modelSelectionButton) {
            modelSelectionButton.addEventListener('click', () => scrollToSection('model-selection-section'));
        }
        if (featureImportanceButton) {
            featureImportanceButton.addEventListener('click', () => scrollToSection('feature-importance-section'));
        }

        window.addEventListener('scroll', () => {
            const sections = document.querySelectorAll('main section');
            const navButtons = document.querySelectorAll('.nav-button');
            const navHeight = document.getElementById('main-nav').offsetHeight;

            let currentActiveSectionId = '';

            for (let i = sections.length - 1; i >= 0; i--) {
                const section = sections[i];
                const sectionTop = section.offsetTop - navHeight - 30;

                if (window.pageYOffset >= sectionTop) {
                    currentActiveSectionId = section.id;
                    break;
                }
            }

            if (currentActiveSectionId === '' && window.pageYOffset < navHeight + 50) {
                currentActiveSectionId = 'project-overview-section';
            }

            navButtons.forEach(button => {
                button.classList.remove('active');

                let targetSectionId = '';
                if (button.id.includes('-nav-button')) {
                    targetSectionId = button.id.replace('-nav-button', '-section');
                }

                if (targetSectionId === currentActiveSectionId) {
                    button.classList.add('active');
                }
            });
        });

        window.dispatchEvent(new Event('scroll'));
    </script>
</body>

</html>