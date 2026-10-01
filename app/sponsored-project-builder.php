<?php
declare(strict_types=1);

/**
 * Sponsored Project Builder V1. Strictly descriptive project specifications:
 * assignments, tasks, evidence, payment and acceptance stay in their existing
 * governed ledgers. These fields do not create tasks or override agreed terms.
 */
function sponsored_project_builder_lines(mixed $input,int $limit=20): array {
    if(is_string($input))$input=preg_split('/\R/u',$input)?:[];
    if(!is_array($input))throw new InvalidArgumentException('Expected one entry per line.');
    if(count($input)>$limit)throw new InvalidArgumentException('Too many project specification entries.');
    $items=[];
    foreach($input as $line){
        if(!is_string($line))throw new InvalidArgumentException('Project specification entries must be text.');
        $value=trim($line);
        if($value==='')continue;
        if(mb_strlen($value)>500)throw new InvalidArgumentException('A specification entry exceeds 500 characters.');
        $items[]=$value;
    }
    return array_values(array_unique($items));
}
function sponsored_project_builder_text(mixed $input,int $limit,string $label): string {
    if(!is_string($input))throw new InvalidArgumentException($label.' must be text.');
    $text=trim($input);
    if(mb_strlen($text)>$limit)throw new InvalidArgumentException($label.' is too long.');
    return $text;
}
function sponsored_project_builder_date(mixed $input,string $label,?string $deadline): ?string {
    $raw=trim((string)($input??''));
    if($raw==='')return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d')!==$raw)throw new InvalidArgumentException($label.' must be YYYY-MM-DD.');
    if($deadline!==null&&$raw>substr($deadline,0,10))
        throw new InvalidArgumentException($label.' cannot fall after the submission deadline.');
    return $raw;
}
function sponsored_project_builder_records(mixed $value,string $kind,?string $deadline): array {
    $limit=$kind==='deliverable'?12:20;
    if($value===null||$value==='')return [];
    if(is_string($value))$value=preg_split('/\R/u',$value)?:[];
    if(!is_array($value)||count($value)>$limit)
        throw new InvalidArgumentException('Too many or invalid project '.$kind.' entries.');
    $result=[];
    foreach($value as $line){
        if(is_string($line)){
            if(trim($line)==='')continue;
            $parts=array_map('trim',explode('|',$line));
            if(count($parts)>4)throw new InvalidArgumentException('Invalid '.$kind.' line. Use the format shown.');
            if($kind==='deliverable'){
                $row=['title'=>$parts[0]??'','format'=>$parts[1]??'','acceptance_criteria'=>$parts[2]??'','due_date'=>$parts[3]??''];
            }else{
                $row=['due_date'=>$parts[0]??'','title'=>$parts[1]??'','success_criteria'=>$parts[2]??''];
                if(count($parts)>3)throw new InvalidArgumentException('A milestone has at most three fields.');
            }
        }elseif(is_array($line))$row=$line;
        else throw new InvalidArgumentException('Invalid '.$kind.' record.');
        $title=sponsored_project_builder_text($row['title']??'',160,ucfirst($kind).' title');
        if($title==='')throw new InvalidArgumentException('Each '.$kind.' needs a title.');
        if($kind==='deliverable'){
            $format=(string)($row['format']??'report');
            $formats=['report','dataset','presentation','research_document','other'];
            if($format==='')$format='report';
            if(!in_array($format,$formats,true))throw new InvalidArgumentException('Unknown deliverable format.');
            $criterion=sponsored_project_builder_text($row['acceptance_criteria']??'',500,'Deliverable acceptance criteria');
            if($criterion==='')throw new InvalidArgumentException('Each deliverable needs acceptance criteria.');
            $result[]=['title'=>$title,'format'=>$format,'acceptance_criteria'=>$criterion,
              'due_date'=>sponsored_project_builder_date($row['due_date']??null,'Deliverable due date',$deadline)];
        }else{
            $criterion=sponsored_project_builder_text($row['success_criteria']??'',500,'Milestone success criteria');
            if($criterion==='')throw new InvalidArgumentException('Each milestone needs success criteria.');
            $result[]=['title'=>$title,'due_date'=>sponsored_project_builder_date($row['due_date']??null,'Milestone date',$deadline),
              'success_criteria'=>$criterion];
        }
    }
    return $result;
}
function sponsored_project_builder_normalize(mixed $input,?string $submissionDeadline=null): array {
    if($input===null||$input==='')$input=[];
    if(is_string($input)){
        try{$input=json_decode($input,true,24,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){throw new InvalidArgumentException('Invalid project specification JSON.');}
    }
    if(!is_array($input))throw new InvalidArgumentException('Project specifications must be structured.');
    $methods=sponsored_project_builder_lines($input['methods']??[],12);
    $allowedMethods=['Desk research','Interviews','Survey','Field study','Data analysis','Literature review','Other'];
    foreach($methods as $method)if(!in_array($method,$allowedMethods,true))
        throw new InvalidArgumentException('Choose an approved research method.');
    $deliverables=sponsored_project_builder_records($input['deliverables']??[],'deliverable',$submissionDeadline);
    $milestones=sponsored_project_builder_records($input['milestones']??[],'milestone',$submissionDeadline);
    $dates=array_values(array_filter(array_column($milestones,'due_date')));
    if($dates!==array_values(array_unique($dates))||$dates!==array_values(array_filter($dates,static fn($x)=>$x!==''))||$dates!==array_values($dates))
        throw new InvalidArgumentException('Milestone dates must be unique.');
    for($i=1;$i<count($dates);$i++)if($dates[$i]<$dates[$i-1])
        throw new InvalidArgumentException('Milestones must be in chronological order.');
    return [
      'target_audience'=>sponsored_project_builder_text($input['target_audience']??'',500,'Target audience'),
      'geography'=>sponsored_project_builder_text($input['geography']??'',300,'Research geography'),
      'scope_in'=>sponsored_project_builder_text($input['scope_in']??'',3000,'In-scope work'),
      'scope_out'=>sponsored_project_builder_text($input['scope_out']??'',3000,'Out-of-scope work'),
      'methods'=>$methods,
      'deliverables'=>$deliverables,
      'milestones'=>$milestones,
    ];
}
function sponsored_project_builder_from_post(array $input): array {
    return [
      'target_audience'=>(string)($input['spec_target_audience']??''),
      'geography'=>(string)($input['spec_geography']??''),
      'scope_in'=>(string)($input['spec_scope_in']??''),
      'scope_out'=>(string)($input['spec_scope_out']??''),
      'methods'=>(array)($input['spec_methods']??[]),
      'deliverables'=>(string)($input['spec_deliverables']??''),
      'milestones'=>(string)($input['spec_milestones']??''),
    ];
}
function sponsored_project_builder_lines_export(array $records,string $kind): string {
    $rows=[];
    foreach($records as $r){
        if($kind==='deliverable')$rows[]=implode(' | ',[(string)$r['title'],(string)$r['format'],(string)$r['acceptance_criteria'],(string)($r['due_date']??'')]);
        else $rows[]=implode(' | ',[(string)($r['due_date']??''),(string)$r['title'],(string)$r['success_criteria']]);
    }
    return implode("\n",$rows);
}
