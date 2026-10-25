<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
$t=(new DateTimeImmutable('2026-10-23',new DateTimeZone('Europe/Berlin')))->getTimestamp();
function interval(int $t,?float $kwh,float $reference=999.):array{return ['start'=>$t,'end'=>$t+900,'cleanedKWh'=>$kwh,'referenceKWh'=>$reference,'tariffCostEuro'=>999.,'fixedCostEuro'=>99.,'qualityFlags'=>0,'unitPriceCt'=>null,'priceSource'=>'missing','priceVerified'=>false];}
function p(int $t,float $price):array{return ['start'=>$t,'end'=>$t+900,'price'=>$price,'source'=>'lichtblick-quote','verified'=>true];}
$prices=LichtBlickFixedPriceComparison::prices([p($t,10),p($t+900,40)],$t,$t+1800,null);
$r=LichtBlickFixedPriceComparison::report([interval($t,1),interval($t+900,9)],$prices,$t,$t+1800,34.);$v=$r['summary'];
check(abs($v['weightedPriceCt']-37)<1e-9,'Weighted average uses 1 kWh at 10 ct and 9 kWh at 40 ct, not the arithmetic 25 ct');
check(abs($v['dynamicCostEuro']-3.7)<1e-9&&abs($v['fixedCostEuro']-3.4)<1e-9&&abs($v['savingEuro']+.3)<1e-9,'Both costs use identical physical kWh and exclude reference normalization, fixed charges and invoice residuals');
check($v['complete']&&abs($v['timeCoveragePercent']-100)<1e-9&&!$v['provisional'],'A complete measured and verified comparison is identified');
$r=LichtBlickFixedPriceComparison::report([interval($t,null),interval($t+900,9)],$prices,$t,$t+1800,34.);
check(!$r['summary']['complete']&&$r['summary']['matchedKWh']===9.&&$r['summary']['weightedPriceCt']===40.,'Missing meter values are excluded from both cost numerators and energy denominator');
$r=LichtBlickFixedPriceComparison::report([interval($t,1),interval($t+900,9)],[$t=>$prices[$t]],$t,$t+1800,34.);
check(!$r['summary']['complete']&&$r['summary']['matchedKWh']===1.&&$r['summary']['weightedPriceCt']===10.,'Unpriced consumption never dilutes the weighted price');
$filled=LichtBlickFixedPriceComparison::prices([p($t+900,-5)],$t,$t+2700,p($t-900,0));
check($filled[$t]['price']===0.&&$filled[$t+1800]['price']===-5.&&$filled[$t]['source']==='carried-forward','Preceding prices, including zero and negative values, fill chronological gaps');
check(count(LichtBlickFixedPriceComparison::prices([p($t+900,20)],$t,$t+1800,null))===1,'A later price is never used to fill an earlier gap');
$r=LichtBlickFixedPriceComparison::report([interval($t,0)],[],$t,$t+900,0.);
check($r['summary']['weightedPriceCt']===null&&$r['summary']['savingPercent']===null&&$r['summary']['fixedCostEuro']===0.,'Zero consumption and zero benchmark avoid divisions by zero');
$r=LichtBlickFixedPriceComparison::report([interval($t,1)],[$t=>$filled[$t]],$t,$t+900,34.);
check($r['summary']['provisional']&&$r['summary']['carriedPriceSeconds']===900,'Carried prices are explicitly provisional');
$dst=(new DateTimeImmutable('2026-10-25',new DateTimeZone('Europe/Berlin')))->getTimestamp();$dstEnd=(new DateTimeImmutable('2026-10-26',new DateTimeZone('Europe/Berlin')))->getTimestamp();$dstRows=[];$dstPrices=[];
for($at=$dst;$at<$dstEnd;$at+=900){$dstRows[]=interval($at,1.);$dstPrices[$at]=p($at,20.);}
$dstReport=LichtBlickFixedPriceComparison::report($dstRows,$dstPrices,$dst,$dstEnd,34.);
check(count($dstRows)===100&&count($dstReport['days'])===1&&$dstReport['summary']['complete']&&$dstReport['summary']['matchedKWh']===100.,'The autumn clock-change day includes both repeated hours in one 25-hour Berlin day');
foreach([-1.,1001.,NAN,INF] as $bad)throws(fn()=>LichtBlickFixedPriceComparison::report([],[],$t,$t+900,$bad),'Invalid benchmark rejected');
// Household API works without a prepared-history connection and uses closed quarters only.
$m=newModule();$m->properties['ConsumptionCounterID']=104;$m->properties['ConsumptionCounterScale']=1.;$m->fail=true;
$GLOBALS['history'][104]=[];for($at=$t-180;$at<=$t+86400+180;$at+=60)$GLOBALS['history'][104][]=['TimeStamp'=>$at,'Value'=>100+($at-$t)/3600];
$directory=(new ReflectionMethod(FixturePrices::class,'priceHistoryDirectory'))->invoke($m);
$store=new LichtBlickPriceStore($directory);$store->merge(LichtBlickPriceStore::identity('10115',0.),[p($t,30.)]);
$before=serialize([$m->properties,$m->attributes,$m->values]);
$r=json_decode($m->GetElectricityPriceComparison('2026-10-23','2026-10-23',34.),true);$v=$r['summary'];
check(abs($v['matchedKWh']-24)<1e-8&&abs($v['weightedPriceCt']-30)<1e-8&&abs($v['savingEuro']-.96)<1e-8,'Whole-house API includes all hours and uses actual meter consumption');
check($v['carriedPriceSeconds']===95*900&&$v['complete']&&$v['provisional']&&$r['priceRecovery']['downloadAttempted'],'Download is attempted before carry-forward; complete estimated coverage remains labelled');
check($before===serialize([$m->properties,$m->attributes,$m->values]),'A failed recovery does not change recommendations, configuration or live price cache');
throws(fn()=>$m->GetElectricityPriceComparison('2026-09-30','2026-10-01'),'Old provider dates are excluded');
throws(fn()=>$m->GetElectricityPriceComparison('2027-01-01','2027-01-01'),'Future dates are rejected');
throws(fn()=>$m->GetElectricityPriceComparison('2026-10-23','2026-10-23',-1),'Negative fixed benchmark rejected');
echo "Consumption-weighted physical energy, identical coverage, fixed-charge exclusion, zero/negative prices, recovery, carry, whole-house API and date/benchmark checks passed.\n";
