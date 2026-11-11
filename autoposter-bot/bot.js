const { chromium } = require('playwright');
const path = require('path');

// ============================================================
// НАЛАШТУВАННЯ
// ============================================================
const WP_SITE_URL = 'https://uaserver.pp.ua';
const API_KEY = 'ua-autopost-secret-key-2024'; // Має співпадати з $api_key у php

const USER_DATA_DIR = path.join(__dirname, 'browser-profile');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Заголовки для всіх API-запитів
const API_HEADERS = {
    'X-API-Key': API_KEY,
};

// ============================================================
// ОСНОВНА ЛОГІКА
// ============================================================
async function runBot() {
    console.log('='.repeat(50));
    console.log('Робот автопостингу запущений.');
    console.log('='.repeat(50));

    const context = await chromium.launchPersistentContext(USER_DATA_DIR, {
        headless: false,
        viewport: { width: 1280, height: 900 },
    });

    const page = await context.newPage();

    // Режим входу: відкриваємо всі дошки з налаштувань WordPress
    if (process.argv.includes('--login')) {
        console.log('\nРЕЖИМ ВХОДУ. Відкриваю дошки з налаштувань WordPress...\n');
        const sites = await fetchSites();
        if (!sites.length) {
            console.log('Не вдалося отримати список дошок. Перевірте, що плагін активний і API_KEY правильний.');
        } else {
            for (const site of sites) {
                console.log(`Відкриваю ${site.key}: ${site.url}`);
                const p = await context.newPage();
                await p.goto(site.url, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
                await sleep(1500);
            }
        }
        console.log('\nВсі сторінки відкриті. Увійдіть у свої акаунти, потім закрийте вікно браузера.');
        return;
    }

    // Основний цикл
    while (true) {
        try {
            console.log('\n--- Перевірка черги ---');
            const tasks = await fetchTasks();

            if (tasks === null) {
                await sleep(60000);
                continue;
            }

            if (!Array.isArray(tasks) || tasks.length === 0) {
                console.log('Черга порожня. Чекаю 60 секунд...');
                await sleep(60000);
                continue;
            }

            console.log(`Знайдено оголошень: ${tasks.length}`);

            // Список дошок беремо з WordPress
            const sitesMap = await fetchSitesMap();
            if (!sitesMap.size) {
                console.log('Список дошок порожній — налаштуйте їх у WordPress.');
                await sleep(60000);
                continue;
            }

            for (const task of tasks) {
                console.log(`\n>>> Обробка: "${task.title}" (ID: ${task.id})`);
                const fullText = task.demo_url
                    ? `${task.description}\n\nДетальніше / Демо: ${task.demo_url}`
                    : task.description;

                const postedSites = [];
                const logs = [];
                const targetSites = Array.isArray(task.sites) ? task.sites : [];

                for (const siteKey of targetSites) {
                    const site = sitesMap.get(siteKey);
                    if (!site) {
                        logs.push(`⚠️ ${siteKey}: дошку не знайдено в налаштуваннях WordPress`);
                        continue;
                    }

                    console.log(`  -> Відкриваю ${siteKey} (${site.url})...`);
                    const result = await openAndPrepare(page, siteKey, site.url, task, fullText);

                    if (result.opened) {
                        postedSites.push(site.label || siteKey);
                        logs.push(`📂 ${siteKey}: сторінку відкрито, заповніть вручну та натисніть "Опублікувати"`);
                        if (result.adUrl) {
                            logs.push(`   🔗 ${result.adUrl}`);
                        }
                    } else {
                        logs.push(`❌ ${siteKey}: ${result.error || 'не вдалося відкрити'}`);
                    }

                    await sleep(3000);
                }

                if (postedSites.length > 0 || logs.length > 0) {
                    await reportTaskDone(task.id, postedSites, logs);
                }
            }

            console.log('\nВсі завдання оброблено. Браузер залишається відкритим.');
            console.log('Завершіть публікацію вручну, потім зачекайте наступного циклу (5 хв).');
            await sleep(300000);

        } catch (error) {
            console.error('Помилка циклу:', error.message);
            await sleep(60000);
        }
    }
}

// ============================================================
// ЗАПИТИ ДО WORDPRESS
// ============================================================
async function fetchTasks() {
    try {
        const response = await fetch(`${WP_SITE_URL}/wp-json/ua-autopost/v1/tasks`, {
            headers: API_HEADERS,
        });
        if (!response.ok) {
            console.error(`API /tasks: ${response.status} ${response.statusText}`);
            return null;
        }
        return await response.json();
    } catch (e) {
        console.error('Помилка запиту /tasks:', e.message);
        return null;
    }
}

async function fetchSites() {
    try {
        const response = await fetch(`${WP_SITE_URL}/wp-json/ua-autopost/v1/sites`, {
            headers: API_HEADERS,
        });
        if (!response.ok) {
            console.error(`API /sites: ${response.status} ${response.statusText}`);
            return [];
        }
        const sites = await response.json();
        return Array.isArray(sites) ? sites : [];
    } catch (e) {
        console.error('Помилка запиту /sites:', e.message);
        return [];
    }
}

async function fetchSitesMap() {
    const sites = await fetchSites();
    const map = new Map();
    for (const site of sites) {
        if (site && site.key && site.url) {
            map.set(site.key, site);
        }
    }
    return map;
}

async function reportTaskDone(taskId, postedSites, logs) {
    try {
        const response = await fetch(`${WP_SITE_URL}/wp-json/ua-autopost/v1/complete/${taskId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...API_HEADERS,
            },
            body: JSON.stringify({
                posted_sites: postedSites,
                log: logs.join('\n'),
            }),
        });
        if (!response.ok) {
            console.error(`API /complete: ${response.status} ${response.statusText}`);
        } else {
            console.log(`Звіт для #${taskId} відправлено.`);
        }
    } catch (e) {
        console.error('Помилка відправки звіту:', e.message);
    }
}

// ============================================================
// ВІДКРИТТЯ СТОРІНКИ ТА СПРОБА ЗАПОВНИТИ
// ============================================================
async function openAndPrepare(page, siteKey, siteUrl, task, fullText) {
    try {
        await page.goto(siteUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await sleep(3000);

        let filled = false;

        const titleSelectors = [
            'input[name="title"]',
            'textarea[name="title"]',
            'input[name*="title"]',
            '#title',
        ];
        for (const sel of titleSelectors) {
            const el = page.locator(sel).first();
            if (await el.isVisible().catch(() => false)) {
                await el.fill(task.title.substring(0, 100)).catch(() => {});
                filled = true;
                break;
            }
        }

        const descSelectors = [
            'textarea[name="description"]',
            'textarea[name*="description"]',
            '#description',
        ];
        for (const sel of descSelectors) {
            const el = page.locator(sel).first();
            if (await el.isVisible().catch(() => false)) {
                await el.fill(fullText).catch(() => {});
                filled = true;
                break;
            }
        }

        if (task.price && Number(task.price) > 0) {
            const priceSelectors = [
                'input[name="price"]',
                'input[name*="price"]',
                '#price',
            ];
            for (const sel of priceSelectors) {
                const el = page.locator(sel).first();
                if (await el.isVisible().catch(() => false)) {
                    await el.fill(String(task.price)).catch(() => {});
                    break;
                }
            }
        }

        console.log(filled
            ? '    Частково заповнено. Завершіть вручну у відкритому вікні.'
            : '    Поля не знайдено (потрібен вибір категорії або вхід). Вікно відкрито вручну.');

        return { opened: true, adUrl: page.url(), filled };
    } catch (e) {
        return { opened: false, error: e.message };
    }
}

runBot().catch(console.error);