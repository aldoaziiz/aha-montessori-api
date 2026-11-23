<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 36px 42px 50px; }
        body { font-family: DejaVu Sans, sans-serif; color: #202124; font-size: 10px; }
        .cover { height: 700px; position: relative; text-align: center; page-break-after: always; }
        .brand { position: absolute; top: 0; left: 0; font-size: 15px; font-weight: bold; }
        .report-date { position: absolute; top: 0; right: 0; color: #667085; }
        .cover-content { padding-top: 145px; }
        .cover-logo { display: block; width: 220px; height: 220px; margin: 0 auto 12px; }
        .cover-title { font-size: 23px; line-height: 1.35; font-weight: bold; margin: 0 0 42px; }
        .child-name { font-size: 17px; font-weight: bold; margin-bottom: 12px; }
        .age-pill { display: inline-block; margin: 0 auto 34px; padding: 10px 24px; border-radius: 18px; background: #6844e8; color: #fff; font-size: 12px; font-weight: bold; }
        .cover-info { width: 100%; border: 1px solid #d9dce5; border-radius: 10px; text-align: left; padding: 18px; }
        .cover-info td { width: 50%; padding: 7px 8px; vertical-align: top; }
        .label { color: #758095; display: block; margin-bottom: 3px; }
        .value { font-weight: bold; }
        .curriculum-title { color: #202124; border-bottom: 1px solid #d9dce5; font-size: 14px; font-weight: bold; margin: 0 0 8px; padding: 0 0 10px; }
        .legend { color: #525f73; margin: 8px 0 18px; }
        .legend-item { display: inline-block; border: 1px solid #e7e9ef; border-left-width: 4px; border-radius: 8px; padding: 4px 8px; color: #202124; }
        .legend-b { border-left-color: #e94332; }
        .legend-db { border-left-color: #ff8428; }
        .legend-m { border-left-color: #ffbf43; }
        .legend-k { border-left-color: #84bc00; }
        .legend-code { display: inline-block; width: 28px; line-height: 12px; padding: 4px 0 5px; border-radius: 11px; color: #fff; font-weight: bold; text-align: center; margin-right: 5px; }
        .legend-code-b { background-color: #e94332; }
        .legend-code-db { background-color: #ff8428; }
        .legend-code-m { background-color: #ffbf43; }
        .legend-code-k { background-color: #84bc00; }
        .area { border: 1px solid #d9dce5; border-radius: 8px; margin: 0 0 17px; }
        .area-title { background: #6844e8; color: #fff; font-size: 13px; font-weight: bold; padding: 11px 13px; border-radius: 7px 7px 0 0; page-break-after: avoid; }
        table.milestones { width: 100%; border-collapse: collapse; }
        table.milestones th { color: #758095; font-weight: normal; text-align: left; border-bottom: 1px solid #e3e5eb; padding: 8px 12px; }
        table.milestones td { padding: 8px 12px; border-bottom: 1px solid #edf0f4; vertical-align: top; }
        table.milestones tr { page-break-inside: avoid; }
        table.milestones tr:last-child td { border-bottom: 0; }
        .progress { width: 48px; text-align: center; vertical-align: middle !important; }
        .progress-badge { display: inline-block; width: 34px; line-height: 12px; padding: 4px 0 5px; border-radius: 12px; color: #fff; font-size: 10px; font-weight: bold; text-align: center; }
        .progress-b { background-color: #e94332; }
        .progress-db { background-color: #ff8428; }
        .progress-m { background-color: #ffbf43; }
        .progress-k { background-color: #84bc00; }
        .progress-na { background-color: #9e9e9e; }
        .subarea { color: #6844e8; font-weight: bold; padding-top: 12px !important; }
        .area-note { min-height: 42px; padding: 12px 13px; border-top: 1px solid #e3e5eb; background: #fafaff; white-space: pre-wrap; }
        .last-page { position: relative; height: 1000px; page-break-before: always; page-break-inside: avoid; }
        .teacher-comments-title { font-size: 14px; font-weight: bold; margin: 0 0 10px; }
        .teacher-comments { min-height: 110px; border: 1px solid #d9dce5; border-radius: 8px; padding: 14px; line-height: 1.6; white-space: pre-wrap; }
        .closing-block { position: absolute; left: 0; right: 0; bottom: 45px; }
        .feedback { height: 115px; border: 1px solid #d9dce5; border-radius: 8px; padding: 12px; color: #687386; }
        .signatures { width: 100%; margin-top: 35px; border-collapse: collapse; text-align: center; }
        .signatures td { width: 33.33%; padding-top: 54px; border-top: 1px solid #aeb4c0; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; text-align: center; color: #758095; border-top: 1px solid #d9dce5; padding-top: 9px; font-size: 9px; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <section class="cover">
        <div class="brand">AHA! Montessori</div>
        <div class="report-date">{{ \Carbon\Carbon::parse($report['report_date'])->locale('id')->translatedFormat('d F Y') }}</div>
        <div class="cover-content">
            <img class="cover-logo" src="{{ public_path('assets/images/ahamon-logo.png') }}" alt="AHA! Montessori">
            <h1 class="cover-title">{{ $report['report_name'] }}</h1>
            <div class="child-name">{{ $report['child_name_snapshot'] }}</div>
            @if($report['age_at_report'])
                <div class="age-pill">{{ $report['age_at_report'] }}</div>
            @endif
            <table class="cover-info">
                <tr>
                    <td><span class="label">Name / Nama</span><span class="value">{{ $report['child_name_snapshot'] }}</span></td>
                    <td><span class="label">Curriculum</span><span class="value">{{ $report['curriculum_name_snapshot'] }}</span></td>
                </tr>
                <tr>
                    <td><span class="label">Date of Birth / Tanggal Lahir</span><span class="value">{{ $report['child_birth_date_snapshot'] ? \Carbon\Carbon::parse($report['child_birth_date_snapshot'])->locale('id')->translatedFormat('d F Y') : '-' }}</span></td>
                    <td><span class="label">Academic Year / Tahun Ajaran</span><span class="value">{{ $report['academic_year'] }}</span></td>
                </tr>
                <tr>
                    <td colspan="2"><span class="label">School Name / Nama Sekolah</span><span class="value">AHA! Montessori</span></td>
                </tr>
            </table>
        </div>
    </section>

    <h2 class="curriculum-title">{{ $report['curriculum_name_snapshot'] }}</h2>
    <div class="legend">
        <span class="legend-item legend-b"><span class="legend-code legend-code-b">B</span>Belum</span> &nbsp;›&nbsp;
        <span class="legend-item legend-db"><span class="legend-code legend-code-db">DB</span>Dengan Bantuan</span> &nbsp;›&nbsp;
        <span class="legend-item legend-m"><span class="legend-code legend-code-m">M</span>Mandiri</span> &nbsp;›&nbsp;
        <span class="legend-item legend-k"><span class="legend-code legend-code-k">K</span>Konsisten</span>
    </div>

    @foreach($report['areas'] as $area)
        <section class="area">
            <div class="area-title">{{ $area['area_name_snapshot'] }}</div>
            <table class="milestones">
                <thead><tr><th>Milestone</th><th class="progress">Progress</th></tr></thead>
                <tbody>
                    @php($lastSubarea = null)
                    @foreach($area['items'] as $item)
                        @if($item->subarea_name_snapshot && $lastSubarea !== $item->subarea_name_snapshot)
                            <tr><td colspan="2" class="subarea">{{ $item->subarea_name_snapshot }}</td></tr>
                            @php($lastSubarea = $item->subarea_name_snapshot)
                        @endif
                        <tr>
                            <td>{{ $item->milestone_name_snapshot }}</td>
                            <td class="progress"><span class="progress-badge progress-{{ strtolower($item->progress_code) }}">{{ $item->progress_code }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="area-note"><strong>Notes</strong><br>{{ $area['area_note'] ?? '' }}</div>
        </section>
    @endforeach

    <section class="last-page">
        <h2 class="teacher-comments-title">Teacher Comments</h2>
        <div class="teacher-comments">{{ $report['teacher_comments'] ?? '' }}</div>
        <div class="closing-block">
            <div class="feedback"><strong>Parent Feedback</strong></div>
            <table class="signatures">
                <tr><td>Principal</td><td>Teacher</td><td>Parents</td></tr>
            </table>
        </div>
    </section>
    <div class="footer">This report is an official document of AHA! Montessori</div>
</body>
</html>
