# local_studentactivity — Дашборд активности учеников

## Установка (5 минут)

### 1. Загрузите плагин на сервер

Скопируйте папку `local_studentactivity` в директорию плагинов Moodle:

```
/var/www/html/moodle/local/studentactivity/
```

Итоговый путь к главному файлу должен быть:
```
/path/to/moodle/local/studentactivity/index.php
```

### 2. Запустите установку через браузер

Войдите в Moodle как администратор и перейдите по адресу:
```
https://ВАШ-САЙТ/admin/index.php
```

Moodle автоматически обнаружит новый плагин и предложит установить его.
Нажмите **«Обновить базу данных Moodle»**.

### 3. Ссылка для добавления на главную страницу

После установки дашборд будет доступен по этому URL:

```
https://ВАШ-САЙТ/local/studentactivity/index.php
```

**Как добавить на главную страницу Moodle:**

1. Войдите как администратор
2. На главной странице нажмите **«Включить режим редактирования»**
3. Нажмите **«+ Добавить блок»** → выберите блок **«HTML»**
4. В блоке вставьте:
   ```html
   <a href="/local/studentactivity/index.php" 
      style="display:inline-block;padding:10px 20px;background:#0f6e56;color:#fff;border-radius:8px;text-decoration:none;font-weight:500">
     📊 Активность учеников
   </a>
   ```
5. Сохраните — ссылка появится на главной странице

---

## Права доступа

Дашборд видят пользователи с ролями:
- Администратор
- Менеджер  
- Создатель курсов
- Учитель (с правами редактирования и без)

Ученики дашборд **не видят**.

## Структура плагина

```
local/studentactivity/
├── version.php          — версия и требования к Moodle
├── index.php            — главная страница дашборда
├── db/
│   └── access.php       — права доступа (capabilities)
├── lang/
│   ├── en/local_studentactivity.php  — строки на английском
│   └── ru/local_studentactivity.php  — строки на русском
└── README.md            — этот файл
```

## Следующие шаги (подключение реальных данных)

Сейчас дашборд работает на демо-данных. Чтобы подключить реальные данные из Moodle:

1. В `index.php` замените блок `$students_json` на запросы к БД через `$DB->get_records()`
2. Используйте таблицы: `mdl_user`, `mdl_logstore_standard_log`, `mdl_assign_submission`, `mdl_course_completions`, `mdl_grade_grades`
3. Для синхронных уроков — `mdl_attendance_log` (если установлен плагин Attendance)
