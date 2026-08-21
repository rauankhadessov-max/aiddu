<?php

namespace App\Services;

class NpaDraftBuilder
{
    public function __construct(private readonly AmendmentCommandCompiler $compiler) {}

    public function build(array $input): array
    {
        $profile = $input['npa_profile'];
        $target = $profile['target_npa'];
        $commands = [];
        $warnings = $profile['warnings'];

        foreach ($input['amendment_snapshots'] as $index => $amendment) {
            $compiled = $this->compiler->compile($amendment);
            $commands[] = [
                'number' => $index + 1,
                'amendment_id' => $amendment['amendment_id'],
                'text' => $compiled['text'],
            ];
            $warnings = array_merge($warnings, $compiled['warnings']);
        }

        $requires = $profile['requires_user_input'];
        $articles = [];
        if ($profile['adopting_act']['status'] === 'resolved') {
            $articles[] = [
                'number' => 1,
                'heading' => null,
                'intro' => 'Внести в '.$target['title'].' следующие изменения и дополнения:',
                'commands' => $commands,
            ];
            $requires[] = 'effective_date_rule';
            $warnings[] = 'Заключительная норма о введении в действие требует подтверждённых условий и пока не сформирована.';
        }

        return [
            'schema_version' => 'draft-npa-v1',
            'project_mark' => 'Проект',
            'act_type' => $profile['adopting_act']['title'],
            'title' => $profile['adopting_act']['status'] === 'resolved'
                ? 'О внесении изменений и дополнений в '.$target['title']
                : null,
            'target_npa' => $target,
            'adopting_act' => $profile['adopting_act'],
            'articles' => $articles,
            'commands' => $commands,
            'requires_user_input' => array_values(array_unique($requires)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
