import * as React from 'react';
import Grid from '@material-ui/core/Grid';
import Input from '@material-ui/core/Input';
import InputLabel from '@material-ui/core/InputLabel';
import MenuItem from '@material-ui/core/MenuItem';
import FormControl from '@material-ui/core/FormControl';
import Typography from '@material-ui/core/Typography';
import Select from '@material-ui/core/Select';
import Button from '@material-ui/core/Button';
import {withStyles} from "@material-ui/core";
import APILogsParameters from './APILogsParameters';

const styles = {
  parametersForm: {
    padding: '12px',
  },
  fields: {
  }
}

class Parameters extends React.Component
{
  static defaultProps = {

  }

  state = {
    parameters: {
      searchType: "",
      textCriteria: "",
      fromDate: "",
      toDate: "",
      server: "",
      session: ""
    }
  }

  _handleChange = (e) => {
    this.setState({
      [e.target.name]: e.target.value
    })
  }

  render() {
    const { searchType } = this.state;
    const { classes, onSearch } = this.props;

    return (
      <div style={{width: '100%', height: '100%'}}>
      <Grid  container spacing={16} alignItems={'flex-end'}>
        <Grid item xs={4}>
          <Typography variant={'headline'}>Search parameters</Typography>
        </Grid>
          <Grid item xs={4}>
            <FormControl fullWidth>
              <InputLabel shrink htmlFor="type-input">
                Select search type
              </InputLabel>
              <Select
                value={searchType}
                onChange={this._handleChange}
                input={<Input name="type" id="type-input" />}
                displayEmpty
                name="searchType"
              >
                <MenuItem value="">
                  <em>Select...</em>
                </MenuItem>
                <MenuItem value={'apiLogs'}>API Logs</MenuItem>
                <MenuItem value={'databaseChanges'}>Database changes</MenuItem>
                <MenuItem value={'batchJobs'}>Batch jobs</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={4}>
            <Button disabled={!searchType} variant="contained" style={{float: 'right'}} onClick={onSearch}>
              Run
            </Button>
          </Grid>
        <Grid item xs={12}>
          { searchType === 'apiLogs' && <APILogsParameters {...this.state.parameters} onChange={this._handleChange} className={classes.parametersForm}></APILogsParameters> }
        </Grid>
      </Grid>
      <div style={{background: 'rgba(0, 0, 0, 0.5)'}}></div>
    </div>
    )
  }
}

export default withStyles(styles)(Parameters);
