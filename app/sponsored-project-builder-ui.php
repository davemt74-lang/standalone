<?php
declare(strict_types=1);
/** Shared Sponsor Project Builder fields for creation and amendment. */
function sponsored_project_builder_form(array $specs=[],bool $expanded=true): string {
    $specs=sponsored_project_builder_normalize($specs);
    $escape=static fn(mixed $x):string=h((string)$x);
    $methodOptions=['Desk research','Interviews','Survey','Field study','Data analysis','Literature review','Other'];
    $options='';
    foreach($methodOptions as $method){
        $checked=in_array($method,$specs['methods'],true)?' checked':'';
        $options.='<label class="spBuilderMethod"><input type="checkbox" name="spec_methods[]" value="'.$escape($method).'"'.$checked.'> '.$escape($method).'</label>';
    }
    $deliverables=$escape(sponsored_project_builder_lines_export($specs['deliverables'],'deliverable'));
    $milestones=$escape(sponsored_project_builder_lines_export($specs['milestones'],'milestone'));
    $open=$expanded?' open':'';
    return '<details class="spBuilder"'.$open.'><summary><span><b>Project Builder</b><small>Audience · scope · methods · deliverables · milestones</small></span></summary><div class="spBuilderContent">'
      .'<p class="meta">Optional but recommended: define exactly what researchers should produce. These specifications are stored in the existing versioned campaign, not a separate project.</p>'
      .'<div class="spBuilderColumns"><label>Target audience<input name="spec_target_audience" maxlength="500" value="'.$escape($specs['target_audience']).'" placeholder="Independent retailers, operators, consumers"></label>'
      .'<label>Target geography<input name="spec_geography" maxlength="300" value="'.$escape($specs['geography']).'" placeholder="United States; Phoenix metropolitan area"></label></div>'
      .'<div class="spBuilderColumns"><label>In-scope research<textarea name="spec_scope_in" rows="4" maxlength="3000" placeholder="Research, topics or markets to include">'.$escape($specs['scope_in']).'</textarea></label>'
      .'<label>Out-of-scope work<textarea name="spec_scope_out" rows="4" maxlength="3000" placeholder="Excluded topics, geographies or activities">'.$escape($specs['scope_out']).'</textarea></label></div>'
      .'<fieldset class="spBuilderMethods"><legend>Approved research methods</legend><div class="spBuilderMethodGrid">'.$options.'</div></fieldset>'
      .'<label>Deliverables <span class="meta">Up to 12. One per line: title | format | acceptance criteria | optional due date (YYYY-MM-DD). Formats: report, dataset, presentation, research_document, other.</span>'
      .'<textarea name="spec_deliverables" rows="5" placeholder="Market landscape | report | At least 10 traceable citations | 2030-12-12">'.$deliverables.'</textarea></label>'
      .'<label>Milestones <span class="meta">Up to 20, chronological. One per line: optional YYYY-MM-DD | title | success criteria. Planned checkpoints only; these do not create tasks or change payment terms.</span>'
      .'<textarea name="spec_milestones" rows="4" placeholder="2030-12-01 | Literature review complete | Sources vetted and documented">'.$milestones.'</textarea></label>'
      .'<div class="spBuilderNotice">Any new revision is recorded in the campaign history. New specifications do not silently alter existing agreed participant terms, payment obligations or submitted research.</div>'
      .'</div></details>';
}
