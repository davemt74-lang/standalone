<?php
declare(strict_types=1);

/**
 * One read model for real and illustrative Sponsored Projects. Never create
 * database campaigns for demos or return a non-public real campaign to guests.
 */
function sponsored_project_detail_sample_specs(string $publicId): array {
    $specs=[
      'sample-market-ai-001'=>[
        'objective'=>'Map practical AI adoption, adoption barriers and purchasing decisions among independent retailers.',
        'questions'=>['Which retail workflows benefit most from AI today?','What prevents smaller retailers from adopting AI?','What evidence supports investment decisions?'],
        'deliverables'=>['Evidence-backed market report','Retailer interview themes','Recommendations with cited sources']
      ],
      'sample-energy-002'=>[
        'objective'=>'Understand how households evaluate residential batteries and choose installation partners.',
        'questions'=>['What motivates a residential battery purchase?','How do upfront costs and financing affect decisions?','Which installer trust signals influence buyers?'],
        'deliverables'=>['Consumer insights report','Buying barrier analysis','Evidence and source bibliography']
      ],
      'sample-food-003'=>[
        'objective'=>'Explore how premium frozen pizza buyers balance taste, convenience, brand trust and price.',
        'questions'=>['Which attributes justify a premium price?','How do crust and topping preferences vary?','What drives repeat purchases?'],
        'deliverables'=>['Concept assessment report','Consumer preference synthesis','Research-backed recommendations']
      ],
    ];
    return $specs[$publicId]??[];
}
function sponsored_project_detail_normalize(array $row,bool $sample=false): array {
    $sampleSpec=$sample?sponsored_project_detail_sample_specs((string)($row['public_id']??'')):[];
    $elig=sponsored_research_campaign_eligibility($row['eligibility']??($row['eligibility_json']??[]));
    $disclosures=sponsored_research_campaign_disclosures($row['disclosures']??($row['disclosure_json']??[]));
    $questions=[];
    foreach((array)($sampleSpec['questions']??$row['questions']??[]) as $q){
        $value=trim((string)(is_array($q)?($q['question']??''):$q));
        if($value!=='')$questions[]=$value;
    }
    return [
      'id'=>(string)$row['public_id'],
      'sample'=>$sample,
      'title'=>(string)$row['title'],
      'organization'=>(string)($row['organization_name']??''),
      'status'=>(string)($row['status']??'open'),
      'access'=>(string)($row['access_mode']??'public'),
      'brief'=>(string)($row['brief']??''),
      'objective'=>(string)($sampleSpec['objective']??$row['objective']??''),
      'questions'=>$questions,
      'sample_deliverables'=>(array)($sampleSpec['deliverables']??[]),
      'requirements'=>(array)($row['requirements']??[]),
      'eligibility'=>$elig,
      'disclosures'=>$disclosures,
      'currency'=>(string)($row['budget_currency']??'USD'),
      'fee_cents'=>(int)($row['researcher_compensation_cents']??0),
      'budget_cents'=>(int)($row['budget_cents']??0),
      'max_participants'=>isset($row['max_participants'])?(int)$row['max_participants']:null,
      'starts_at'=>$row['starts_at']??null,
      'submission_deadline'=>$row['submission_deadline']??null,
      'review_deadline'=>$row['review_deadline']??null,
      'revision'=>(int)($row['current_revision']??0),
      'sponsor_agent'=>(string)($row['research_agent_name']??''),
    ];
}
function sponsored_project_detail_resolve(PDO $pdo,?array $viewer,string $publicId): ?array {
    $publicId=trim($publicId);
    if($publicId===''||mb_strlen($publicId)>128)return null;
    // The Admin toggle is authoritative even for a direct sample-detail URL.
    if(str_starts_with($publicId,'sample-')){
        foreach(sponsored_project_sample_projects($pdo) as $row)
            if(hash_equals((string)$row['public_id'],$publicId))
                return ['project'=>sponsored_project_detail_normalize($row,true),'role'=>'sample','eligibility'=>null];
        return null;
    }
    if(!sponsored_research_campaigns_ready($pdo))return null;
    $full=sponsored_research_campaign_by_public($pdo,$publicId);
    if(!$full)return null;
    if($viewer){
        try{
            $managed=sponsored_research_campaign_require_manage($pdo,$viewer,$publicId);
            return ['project'=>sponsored_project_detail_normalize($managed),'role'=>'sponsor','eligibility'=>null];
        }catch(Throwable $ignored){/* Do not reveal account details on failed manage access. */}
        if(research_account_is_approved($pdo,$viewer)
          && sponsored_research_campaign_visible_to_researcher($pdo,$viewer,$full)){
            return [
              'project'=>sponsored_project_detail_normalize($full),
              'role'=>'researcher',
              'eligibility'=>sponsored_research_campaign_eligibility_check($pdo,$viewer,$full),
            ];
        }
    }
    // The operational public getter also enforces open/public, capacity,
    // start date, deadline and positive researcher compensation.
    $public=sponsored_project_public_get($pdo,$publicId);
    if(!$public)return null;
    return ['project'=>sponsored_project_detail_normalize($public),'role'=>'visitor','eligibility'=>null];
}
function sponsored_project_detail_money(array $project,int $cents): string {
    return (string)$project['currency'].' '.number_format($cents/100,2);
}
function sponsored_project_detail_policy_label(string $value): string {
    return ucwords(str_replace('_',' ',$value));
}
