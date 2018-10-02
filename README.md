#phpMultiProcessor
Multi processor for PHP

##About
This library gives you a way to parallel/asynchronously process tasks that would take quite a while by splitting up work and executing tasks in parallel by forking the running PHP process.
Processor cores are used more efficiently this way as well. You can for example fork as many times as there are cores available on a machine. Or more if the process is easy on CPU requirements. 

###Use cases:
- Processing multiple HTTP/REST API calls at the same time for API's that do not allow bulk actions
- Splitting bulk hashing operations over multiple cores

## Prerequisites/Requirements
- PHP 7.2.0 or greater