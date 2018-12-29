####What:####
1. Download survey results

####Why:####
1. Original reason: save account seats  
2. My work: enable faster and simpler access to results!

####How:####
1. Use API
    + download surveys - provide a link
    + download results - responses in SurveyMonkey's term

####Note:####
1. 500 calls daily limit, exceeded many times, Albert concerned
    + had to make it update 4 hours cycle not 1 hour! can't wait to have webhooks! just not enough time! 
    
2. Code is complicated, use json object to understand easier.
    + Spent 2 days on adding custom variables to the final results
        - lost hope, got completedly confused in the nested (loops and calls) of (code and objects)
    + Then resorted to json - nicely formatted, and figured out the problem in minuts
    + Use survey monkey downloaded xls for comparison
    
3. For security the code on wcms and stg are pure code without git repo, and uno
    + So it's better to work on work laptop, because it's easier to copy to wcms and stg
    
4. For more efficient work, work on local host or even a php -S server    
    + vi doesn't have syntax colorking and matching
    + multiple local windows editors viewing working on different parts sections of code!
    + server often disconnects and freezes
    + danger of being used by users in the middle of development

####Todo:####
1. Save API calls
    + Update top 10 only to save API calls - done
    + add a link "more" to show all
    
2. Log
    + Fix IP so I know where it is called
    + Log and email the downloaded response, currently not showing that
    
3. Custom variables
    + Test other surveys to see how custom variables work

4. Move from wcms-dev to wcms, so can WFH without jumpbox!
    + before moving completed, use cron job to download in wcms-dev, it can sync to stg
	+ when moving, move cold and data both over to wcms
    
5. Use webhooks
    + learn web hooks
    + reduce API calls, 
    + increase timeliness! not 4 hours, not 2, not 1, minute! 
    
6. Password protect
    + Stopped working on load balancer, need to figure it out
    
7. Front end
    + show progress for downloading each individual response
    + Make the number of responses button bigger, easier to click! and prettier, and giving more confidence! and more welcoming! and more attractive! - done! 


