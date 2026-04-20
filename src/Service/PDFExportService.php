<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

class PDFExportService
{
    public function __construct()
    {
    }

    /**
     * Generate PDF for user activity logs.
     *
     * @param array<array<string, mixed>> $activities
     */
    public function generateUserActivityLogPDF(string $userName, string $userEmail, array $activities): string
    {
        $html = $this->renderUserActivityLogHTML($userName, $userEmail, $activities);
        return $this->renderPDF($html);
    }

    /**
     * Generate PDF for platform activity feed.
     *
     * @param array<array<string, mixed>> $activities
     */
    public function generatePlatformFeedPDF(array $activities): string
    {
        $html = $this->renderPlatformFeedHTML($activities);
        return $this->renderPDF($html);
    }

    /**
     * @param array<array<string, mixed>> $activities
     */
    private function renderUserActivityLogHTML(string $userName, string $userEmail, array $activities): string
    {
        $generatedDate = (new \DateTime())->format('Y-m-d H:i:s');
        $activityCount = count($activities);

        $activityRows = '';
        foreach ($activities as $activity) {
            $module = htmlspecialchars((string) ($activity['module'] ?? 'N/A'), ENT_QUOTES);
            $action = htmlspecialchars((string) ($activity['action'] ?? 'N/A'), ENT_QUOTES);
            $targetName = htmlspecialchars((string) ($activity['target_name'] ?? 'N/A'), ENT_QUOTES);
            $content = htmlspecialchars((string) ($activity['content'] ?? ''), ENT_QUOTES);
            $createdAt = (string) ($activity['created_at'] ?? 'N/A');

            $contentDisplay = $content !== '' ? sprintf('<small>%s</small>', $content) : '';

            $activityRows .= sprintf(
                '<tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 10px;">%s %s</td>
                </tr>',
                $module,
                $action,
                $targetName,
                $createdAt,
                $contentDisplay,
            );
        }

        if ($activityRows === '') {
            $activityRows = '<tr><td colspan="5" style="padding: 12px; text-align: center; color: #999;">No activities found</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Activity Log Export</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .user-info {
            background-color: #f9f9f9;
            padding: 12px;
            margin: 15px 0;
            border-left: 4px solid #007bff;
            font-size: 12px;
        }
        .user-info strong {
            color: #333;
        }
        .meta-info {
            font-size: 11px;
            color: #666;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 11px;
        }
        th {
            background-color: #007bff;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 8px;
        }
        .footer {
            margin-top: 30px;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #e0e0e0;
            padding-top: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Activity Log Export</h1>
        
        <div class="user-info">
            <strong>User:</strong> {$userName}<br>
            <strong>Email:</strong> {$userEmail}<br>
            <strong>Total Activities:</strong> {$activityCount}
        </div>
        
        <div class="meta-info">
            Generated on: {$generatedDate}
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Date</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                {$activityRows}
            </tbody>
        </table>
        
        <div class="footer">
            <p>This is a confidential document. Activity logs are provided for audit and compliance purposes.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * @param array<array<string, mixed>> $activities
     */
    private function renderPlatformFeedHTML(array $activities): string
    {
        $generatedDate = (new \DateTime())->format('Y-m-d H:i:s');
        $activityCount = count($activities);

        $activityRows = '';
        foreach ($activities as $activity) {
            $module = htmlspecialchars((string) ($activity['module'] ?? 'N/A'), ENT_QUOTES);
            $action = htmlspecialchars((string) ($activity['action'] ?? 'N/A'), ENT_QUOTES);
            $userName = htmlspecialchars((string) ($activity['user_name'] ?? 'Unknown'), ENT_QUOTES);
            $targetName = htmlspecialchars((string) ($activity['target_name'] ?? 'N/A'), ENT_QUOTES);
            $content = htmlspecialchars((string) ($activity['content'] ?? ''), ENT_QUOTES);
            $createdAt = (string) ($activity['created_at'] ?? 'N/A');

            $contentDisplay = $content !== '' ? sprintf('<small>%s</small>', $content) : '';

            $activityRows .= sprintf(
                '<tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 11px;">%s</td>
                    <td style="padding: 8px; font-size: 10px;">%s %s</td>
                </tr>',
                $module,
                $action,
                $userName,
                $targetName,
                $createdAt,
                $contentDisplay,
            );
        }

        if ($activityRows === '') {
            $activityRows = '<tr><td colspan="6" style="padding: 12px; text-align: center; color: #999;">No activities found</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Platform Activity Feed Export</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
            border-bottom: 3px solid #28a745;
            padding-bottom: 10px;
        }
        .meta-info {
            background-color: #f9f9f9;
            padding: 12px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
            font-size: 12px;
        }
        .meta-info strong {
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 11px;
        }
        th {
            background-color: #28a745;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 8px;
        }
        .footer {
            margin-top: 30px;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #e0e0e0;
            padding-top: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Platform Activity Feed Export</h1>
        
        <div class="meta-info">
            <strong>Total Activities:</strong> {$activityCount}<br>
            <strong>Generated on:</strong> {$generatedDate}
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Action</th>
                    <th>User</th>
                    <th>Target</th>
                    <th>Date</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                {$activityRows}
            </tbody>
        </table>
        
        <div class="footer">
            <p>This is a confidential platform activity report for audit and compliance purposes.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function renderPDF(string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
